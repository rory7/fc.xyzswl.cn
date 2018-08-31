<?php

namespace app\sso\controller;

use app\common\controller\Base;
use app\common\controller\ModuleBase;
use app\common\model\NodeIndex;
use app\sms\providers\Aliyun;
use app\common\model\Sites;
use app\common\model\SitesWechat;
use app\common\model\UserRoles;
use app\common\model\Users;
use app\common\payment\micropay\JsApiPay;
use app\common\util\CURL;
use app\common\util\forms\input;
use app\common\util\wechat\wechat;
use app\core\util\MhcmsDistribution;
use app\wechat\util\MhcmsWechatEngine;
use app\common\model\SmsCode;
use think\Cache;
use think\Cookie;
use think\Db;
use think\Loader;
use think\Model;
use think\Session;

/**
 * @property  site_wechat
 */
class Passport extends ModuleBase
{
    private $qq_config;

    public function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->view->no_check_area = 1;
    }

    public function qq_login()
    {
        $user_id = check_user();
        if ($user_id) {
            return $this->view->fetch('qq_login');
        } else {
            $state = md5(uniqid(rand(), TRUE));
            session('state', $state);
            $site_id = (int)input('param.site_id');
            Cookie::set('site_id', $site_id);
            $login_url = 'https://graph.qq.com/oauth2.0/authorize?response_type=code&client_id=' . $this->qq_config['APP_ID']['value'] . '&redirect_uri=' . urlencode(url('qq_login_callback')) . "?site_id=$site_id" . '&state=' . $state . '&scope=';
            header("Location:{$login_url}");
            die;
        }
    }

    /**
     *
     */

    public function qq_login_callback()
    {
        if ($_REQUEST['state'] == session('state')) {
            $curl = new CURL();
            $token_url = 'https://graph.qq.com/oauth2.0/token?grant_type=authorization_code&' . 'client_id=' . $this->qq_config['APP_ID']['value'] . '&redirect_uri=' . urlencode(url('qq_login_callback')) . '&client_secret=' . $this->qq_config['APP_KEY']['value'] . '&code=' . $_REQUEST['code'];
            $response = $curl->get($token_url);
            if (strpos($response, 'callback') !== false) {
                $lpos = strpos($response, '(');
                $rpos = strrpos($response, ')');
                $response = substr($response, $lpos + 1, $rpos - $lpos - 1);
                $msg = json_decode($response);
                echo '<h3>error:</h3>' . $msg->error;
                echo '<h3>msg  :</h3>' . $msg->error_description;
                die;
            }
            $params = array();
            parse_str($response, $params);
            if (!empty($params)) {
                $graph_url = 'https://graph.qq.com/oauth2.0/me?access_token=' . $params['access_token'];
                $str = $curl->get($graph_url);
                if (strpos($str, 'callback') !== false) {
                    $lpos = strpos($str, '(');
                    $rpos = strrpos($str, ')');
                    $str = substr($str, $lpos + 1, $rpos - $lpos - 1);
                }
                $user = json_decode($str, true);
                if (isset($user['error'])) {
                    echo '<h3>error:</h3>' . $user['error'];
                    echo '<h3>msg  :</h3>' . $user['error_description'];
                    die;
                }

                if (!empty($user['openid'])) {
                    $data = array('type' => 'qq_id', 'qq_id' => $user['openid'], 'client_id' => $user['client_id'], 'token' => $params['access_token']);
                    $this->connect_login($data);
                }
            }

        }
    }


    /**
     *
     * 创建第三方登录的账号
     */
    private function connect_login($data)
    {
        $site_id = (int)input('param.site_id');
        $site = Sites::get($site_id);
        $where = [$data['type'] => $data[$data['type']]];
        $user = Users::get($where);//->getConnectByOpenid($data['type'], $data['open_id']);
        if (empty($user)) { //new user
            $connect[$data['type']] = $data[$data['type']];
            $connect['user_role_id'] = 2;
            $connect['site_id'] = $site_id;
            $connect['user_status'] = 1;
            $user = Users::create($connect);
        }
        $this->log_user_in($user);
        if ($site) {
            $url = nb_url(['r' => '/home/index/index', 'reload_menu' => 1], $site['site_id']);
            header("location:" . $url);
        } else {
            echo "登陆成功";
        }
        die;
    }

    /**
     * log out
     */
    public function logout($self_call = false)
    {
        global $_W;
        $site_id = (int)input('param.site_id');
        Session::set('auth_info', null);
        Session::set('user_id', null);
        Session::set('user_role_id', null);
        Session::set('user_name', null);
        Cookie::set('user_id', null);
        $site = Sites::get($site_id);

        if (empty($_W['global_config']['sso_domain'])) {
            $sso_domain = $_SERVER['HTTP_HOST'];
        } else {
            $sso_domain = $_W['global_config']['sso_domain'];
        }
        if ($sso_domain == "www.zxw.bz") {
            //    $sso_domain = $_SERVER['HTTP_HOST'];
        }
        $for = SITE_PROTOCOL . $sso_domain . "/house/index/index";
        if (is_weixin() && $_W['site']['config']['force_wechat']) {
            $url = SITE_PROTOCOL . $sso_domain . "/sso/passport/wx_login?to_site_id=" . $this->site['id'] . "&forward=" . $for;
        } else {

            $url = SITE_PROTOCOL . $sso_domain . "/sso/passport/login?to_site_id=" . $this->site['id'] . "&forward=" . $for;
        }

        header("location:$url");
        die();
        if (!$self_call) {
            $this->view->forward = $url = nb_url(['r' => '/'], $site['site_id']);
            //    $this->success("注销成功！", $url);
            return $this->view->fetch();
        }


    }

    public function sso_logout()
    {
        Session::set('auth_info', null);
        Session::set('user_id', null);
        Session::set('user_role_id', null);
        Session::set('user_name', null);
        Cookie::set('user_id', null);
        return jsonp(['random_auth' => '', 'user_id' => 0]);
    }

    /**
     * 获取当前用户登录的ID
     */

    public function get_login_info()
    {
        //set cookie for current site
        $user = check_user();
        if ($user) {
            //test($user->id . "\t" . $this->request->ip() . "\t" . $this->request->type() . "\t" . $this->request->header('user-agent') . "\t" . time(), 'ENCODE');
            $user->random_auth = crypt_auth($user->id . "\t" . $this->request->ip() . "\t" . $this->request->type() . "\t" . $this->request->header('user-agent') . "\t" . time(), 'ENCODE');


            $user->save();

            $ret = ['random_auth' => $user->random_auth, 'user_id' => $user->id];
            return jsonp($ret);
        }
        return jsonp(['random_auth' => '', 'user_id' => 0]);
    }

    /**登录 地址
     * @param $auth_str
     * @return mixed
     */
    public function sso_login()
    {
        global $_W, $_GPC;
        //得到加密过后的字符串
        $auth_str = input('param.auth_str');
        $current_user = Users::get((int)$_GPC['user_id']);
        $info = crypt_auth($auth_str, 'DECODE');
        $info = explode('	', $info);
        if (!empty($auth_str) && $current_user['random_auth'] == $auth_str) {
            $current_user->random_auth = "";
            $current_user->save();
            $current_user->log_user_in();
            $data['code'] = 1;
            $msg = "operation success!";
        } else {
            $data['code'] = 2;
            $this->logout(true);
            $msg = "operation failed!";
        }
        $data['msg'] = $msg;
        return $data;
    }

    public function send_code()
    {
        $code = mt_rand(1000, 9999);
        $params = [
            'code' => $code
        ];

        $mobile = $_GET['mobile'];
        $tpl_id = config('alidayu.template_code');
        $resp = Aliyun::send($mobile, $params, $tpl_id);
        if($resp['code'] == 1){
            session('code', $code);
            SmsCode::create(['code'=> $code,'mobile'=>$mobile,'time'=>time()]);
        }
        echo json_encode($resp);
    }

    /**
     * 注册登陆以后跳转地址处理方案：
     * 如果没有制定任何地址 则跳回到主页 或者会员中心
     * 指定site_id则跳转到对应的网站主页
     * 没有指定则跳转到当前站点
     * 如果指定了url则跳转到url
     * @return mixed
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function register()
    {
        global $_W;
        $site_id = input('param.site_id', $_W['site']['id']);
        if ($site_id) {
            $site = Sites::get(['id' => $site_id]);
        } else {
            $site = $this->site;
        }
        $forward = input('param.forward', '');
        $forward = $forward ? $forward : Cookie::get("forward");

        if (strpos($forward, "login") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "register") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "logout") !== false) {
            $forward = "/";
        }

        if ($this->isPost()) {
            $data = input('param.data/a');
           if($data['code'] != session('code')){
               $this->zbn_msg('验证码错误');
           }

            $foreword_url = "";
            $code = 2;
            if ($data['password1']) {
                $user_data['password'] = $data['password'] == $data['password1'] ? $data['password'] : $this->zbn_msg("两次密码必须一样", 2);
            } else {
                $user_data['password'] = $data['password'];
            }
            $user = new Users();
            $user_data['user_name'] = $data['user_name'];//input('param.user_name');
            if (!is_phone($user_data['user_name'])) {
                $this->zbn_msg("用户名必须是手机号码", 2);
            }
            $user_data['site_id'] = $site['id'];
            $user_data['user_crypt'] = random(6);
            $user_data['pass'] = crypt_pass($user_data['password'], $user_data['user_crypt']);
            $validate = Loader::validate('Users');
            $user_data['status'] = $user_data['user_status'] = 99;
            $user_data['user_role_id'] = 2;
            $user_data['created'] = date("Y-m-d H:i:s");
            $user_data['sex'] = '保密';


            $result = $validate->check($user_data);
            if (!$result) {
                $msg = $validate->getError();
            } else {
                $user_data = $user->setDefaultValueByFields($user_data, array('email_verify', 'parent_id', 'creator_id', 'last_update', 'is_verify', 'is_vip', 'is_mobile_verify', 'is_admin'));
                if ($res = $user->allowField(true)->validate(true)->save($user_data)) {
                    $user->log_user_in();
                    $code = 1;
                    $msg = "注册成功";
                    $from_uid = $_W['refer'];
                    if ($from_uid) {
                        // send data to
                        MhcmsDistribution::make_down_line($user['id'], $from_uid);
                    }
                    $foreword_url = url('house/index/index');
                } else {
                    $code = 2;
                    $msg = $res;
                    $foreword_url = "";
                }
            }
            if ($code == 1) {
                $this->zbn_msg($msg, $code, true, 2000, "'$foreword_url'");
            } else {
                $this->zbn_msg($msg);
            }
        } else {
            $this->view->site_id = $site_id;
            return $this->view->fetch();
        }
    }

    /**
     * @return mixed
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function login()
    {
        global $_W, $_GPC;
        $foreword_url = input('param.forward', HTTP_REFERER);
        $to_site_id = input('param.to_site_id', $_W['site']['id']);
        Cookie::set('forward', $foreword_url);
        if ($this->isPost()) {
            $data = input('param.data/a');
            $code = input('param.code');
            //验证码检测
            if (!captcha_check($code)) {
                //    $this->zbn_msg("verify code, error", 2, '', 1000, "", "\"reset_code('#code')\"");
            }
            $where = ['user_name' => $data['user_name']];
            $current_user = Users::get($where);
            if (!$current_admin) {
                $current_admin = Users::get(['mobile' => $data['user_name']]);
            }

            if ($current_user) {
                //获取角色信息
                $current_user_role = UserRoles::get($current_user['user_role_id']);
                if ((int)$current_user_role['status'] == 0) {
                    $this->zbn_msg("对不起，您所在的用户组已经被管理员禁用！ ", 2, "false", 4000);
                }
            }
            if ($current_user && $current_user['pass'] == crypt_pass($data['password'], $current_user['user_crypt'])) {
                $current_user->log_user_in();
                if (empty($forward)) {
                    $url = nb_url(['r' => '/house/index/index'], $to_site_id);
                } else {
                    $url = $forward;
                }
                // 登录以后跳转
                $foreword_url = $foreword_url ? $foreword_url : $url;

                $this->zbn_msg("登录成功!", 1, 'true', 2000, "'$foreword_url'");


            } else {
                $this->zbn_msg("账号验证失败!", 2);
            }
        } else {
            $this->view->to_site_id = $to_site_id;
            $this->view->forward = $foreword_url;
            //todo
            if ($this->site['config']['member']['force_wechat']) {
                if (is_weixin()) {
                    $mhcms_wechat = url('sso/passport/wx_login', ['forward' => urlencode($foreword_url), 'to_site_id' => $to_site_id]);
                } else {
                    $mhcms_wechat = url('sso/passport/wx_subscribe', ['forward' => urlencode($foreword_url), 'to_site_id' => $to_site_id]);
                    if (!$this->site_wechat) {
                        $this->error("需要集成微信才能使用微信登录");
                    }
                }

                header("location:$mhcms_wechat");
                die();
            } else {
                if ($this->user) {
                    $foreword_url = url('house/index/index');
                    $target = parse_url($foreword_url);
                    $url = url('sso/passport/jump', ['forward' => urlencode($foreword_url)], 'html', $target['host']);


                    //$url = nb_url(['r'=>'sso/passport/jump'] , $to_site_id , ['forward' =>urlencode($foreword_url) ]);

                    header("location:$url");
                    die();
                } else {
                    return $this->view->fetch();
                }

            }


        }
    }

    public function mhcms_wx_login($uuid)
    {
        global $_GPC;
        if ($this->isPost()) {
            //pc 访问
            //todo check the user_id and login
            $new_uuid = Cache::get("mhcms_wechat_login:" . $uuid);

            if ($uuid && $uuid != $new_uuid && is_numeric($new_uuid)) {
                $user = Users::get(['id' => $new_uuid]);
                if ($user) {
                    $status = "success";
                    $user->log_user_in();
                }

            } elseif ($uuid == $new_uuid) {
                $status = "wait";
            } else {
                $status = "expired";
            }
            return [
                'status' => $status,
                'new_uuid' => $new_uuid,
                'uuid' => $uuid
            ];
        } else {
            //微信访问

            if (!is_weixin()) {
                $this->error("请使用微信扫描二维码");
            }
            $uuid = Cache::get("mhcms_wechat_login:" . $uuid);
            if ($uuid) {
                $this->wx_login($uuid);
                $to_url = url('sso/passport/wx_login', ['site_id' => $_GPC['site_id'], 'forward' => $_GPC['forward'], 'uuid' => $_GPC['uuid']]);

                header("location:$to_url");

            } else {
                $this->error("对不起，二维码已经过期，请刷新重试");
            }
        }

    }

    public function wx_register($uuid = 0)
    {
        global $_W, $_GPC;
        
        $site_id = $_GPC['site_id'] ? $_GPC['site_id'] : $_W['site']['id'];//input('param.site_id', 0);

        if (is_weixin()) {
            if (!$_W['account']) {
                return;
            }

            $wechat = MhcmsWechatEngine::create($_W['account']);
            $fans_info = $wechat->oauth_user_info_login();
        } else {
            return;
        }

        $where['openid'] = $fans_info['openid'];
        $_fan = $_W['wechat_fans_model']->where($where)->find();

        $new_fan = [];
        $new_fan['openid'] = $fans_info['openid'];
        $new_fan['avatar'] = $fans_info['headimgurl'];
        $new_fan['nickname'] = $fans_info['nickname'];            
        $new_fan['subscribe'] = (int)$fans_info['subscribe'];
        $new_fan['province'] = $fans_info['province'];
        $new_fan['city'] = $fans_info['city'];
        $new_fan['gender'] = $fans_info['sex'];
        $new_fan['country'] = $fans_info['country'];
        $new_fan['follow_time'] = date("Y-m-d H:i:s", $fans_info['subscribe_time']);
        $new_fan['site_id'] = $site_id;

        //test($new_fan);
        //创建粉丝信息
        if (empty($new_fan['openid'])) {
            return;
        }
        if (!$_fan) {
            if ($new_fan['openid']) {
                $new_fan = $_W['wechat_fans_model']->setDefaultValueByFields($new_fan, ['id']);
                $_W['wechat_fans_model']->insert($new_fan);
            }

            $_fan = $new_fan;
        }

        if ($_fan && !$_fan['nickname']) {
            $_W['wechat_fans_model']->where(['openid' => $_fan['openid']])->update($new_fan);
        }

        if ($_fan['user_id']) {
            $user = Users::get(['id' => $_fan['user_id']]);
        }
        //先处理 unionid
        //union 处理失败 处理openid
        if (!$user) {
            //todo process unionid
            if ($fans_info['unionid']) {
                $union_where = [];
                $union_where['wechat_unionid'] = $fans_info['unionid'];
                // try to find the union user
                if ($user = Users::get($union_where)) {
                    $new_fan['user_id'] = $user['id'];
                    $_W['wechat_fans_model']->where(['openid' => $_fan['openid']])->update($new_fan);
                }
            }
        }
        // test($fans_info);
        if ($user) {
            if ($fans_info['unionid'] && !$user['wechat_unionid']) {
                $user->wechat_unionid = $fans_info['unionid'];
            }
            if (empty($user->avatar) && $fans_info['headimgurl']) {
                $user->avatar = $fans_info['headimgurl'];
            }
            if (empty($user->nickname) && $fans_info['nickname']) {
                $user->nickname = $fans_info['nickname'];
            }
            $user->save();
            $user->log_user_in();
        } else {
            //todo 创建用户账号
            $user = new Users();
            $user_data['user_name'] = $_W['openid'] = $_fan['openid'];
            Cookie::set("openid_" . $this->site['id'], $_W['openid']);
            $user_data['site_id'] = $site_id;
            $user_data['user_crypt'] = random(6);
            switch ((int)$fans_info['sex']) {
                case 1:
                    $user_data['sex'] = '男';
                    break;
                case 2:
                    $user_data['sex'] = '女';
                    break;
                default:
                    $user_data['sex'] = '保密';
                    break;
            }

            $user_data['avatar'] = $fans_info['headimgurl'];
            $user_data['nickname'] = $fans_info['nickname'];

            $user_data['pass'] = "NOTSET"; //crypt_pass($user_data['password'], $user_data['user_crypt']);

            //默认用户状态
            $user_data['status'] = 99;
            //默认用户组
            $user_data['user_role_id'] = 4;
            $user_data['created'] = date("Y-m-d H:i:s");
            if ($fans_info['unionid']) {
                $user_data['wechat_unionid'] = $fans_info['unionid'];
            }
            $user_data = $user->setDefaultValueByFields($user_data, ['id', 'last_update']);
            //不存在用户
            if ($res = $user->allowField(true)->validate(true)->save($user_data)) {
                //注册成功以后 绑定
                $connect['user_id'] = $user['id'];
                $_W['wechat_fans_model']->where(['openid' => $fans_info['openid']])->update($connect);
                $user->log_user_in();
                $from_uid = $_W['refer'];
                if ($from_uid) {
                    // send data to
                    MhcmsDistribution::make_down_line($user['id'], $from_uid);
                }
            } else {
                return;
            }
        }
    }

    /**
     *
     */
    public function wx_login($uuid = 0)
    {
        global $_W, $_GPC;
        $forward = input('param.forward', '');
        $forward = $forward ? $forward : Cookie::get("forward");

        if (strpos($forward, "login") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "register") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "logout") !== false) {
            $forward = "/";
        }

        $site_id = $_GPC['site_id'] ? $_GPC['site_id'] : $_W['site']['id'];//input('param.site_id', 0);

        if (is_weixin()) {
            if (!$_W['account']) {
                $this->error("站点尚未接入微信，无法使用微信进行登录");
            }

            $wechat = MhcmsWechatEngine::create($_W['account']);
            $fans_info = $wechat->oauth_user_info_login();

            //$fans_info = $wechat->oauth_user_login();

        } else {
            //32位随机代码
            Cache::set("mhcms_wechat_login:" . $this->uuid, $this->uuid);
            $login_url = url('sso/passport/mhcms_wx_login', ['site_id' => $site_id, 'uuid' => $this->uuid, 'forward' => urlencode($forward)], true, true);
            $this->view->code = url('core/common_service/str_to_qr') . "?str=" . urlencode($login_url);
            $this->view->uuid = $this->uuid;
            $this->view->mhcms_wx_login_api = $login_url;
            $this->view->forward = urldecode($forward);
            return $this->view->fetch();
        }

        $where['openid'] = $fans_info['openid'];
        $_fan = $_W['wechat_fans_model']->where($where)->find();

        $new_fan = [];
        $new_fan['openid'] = $fans_info['openid'];
        // if ($fans_info['country']) {
            $new_fan['avatar'] = $fans_info['headimgurl'];
            $new_fan['nickname'] = $fans_info['nickname'];            
            $new_fan['subscribe'] = (int)$fans_info['subscribe'];
            $new_fan['province'] = $fans_info['province'];
            $new_fan['city'] = $fans_info['city'];
            $new_fan['gender'] = $fans_info['sex'];
            $new_fan['country'] = $fans_info['country'];
            $new_fan['follow_time'] = date("Y-m-d H:i:s", $fans_info['subscribe_time']);
            $new_fan['site_id'] = $site_id;
        // }

        //test($new_fan);
        //创建粉丝信息
        if (empty($new_fan['openid'])) {
            echo "请求失败了 ，请重新扫描！";
            die();
        }
        if (!$_fan) {
            if ($new_fan['openid']) {
                $new_fan = $_W['wechat_fans_model']->setDefaultValueByFields($new_fan, ['id']);
                $_W['wechat_fans_model']->insert($new_fan);
            }

            $_fan = $new_fan;
        }

        if ($_fan && !$_fan['nickname']) {
            $_W['wechat_fans_model']->where(['openid' => $_fan['openid']])->update($new_fan);
        }

        if ($_fan['user_id']) {
            $user = Users::get(['id' => $_fan['user_id']]);
        }
        //先处理 unionid
        //union 处理失败 处理openid
        if (!$user) {
            //todo process unionid
            if ($fans_info['unionid']) {
                $union_where = [];
                $union_where['wechat_unionid'] = $fans_info['unionid'];
                // try to find the union user
                if ($user = Users::get($union_where)) {
                    $new_fan['user_id'] = $user['id'];
                    $_W['wechat_fans_model']->where(['openid' => $_fan['openid']])->update($new_fan);
                }
            }
        }
        // test($fans_info);
        if ($user) {
            if ($fans_info['unionid'] && !$user['wechat_unionid']) {
                $user->wechat_unionid = $fans_info['unionid'];
            }
            if (empty($user->avatar) && $fans_info['headimgurl']) {
                $user->avatar = $fans_info['headimgurl'];
            }
            if (empty($user->nickname) && $fans_info['nickname']) {
                $user->nickname = $fans_info['nickname'];
            }
            $user->save();
            $user->log_user_in();
        } else {
            //todo 创建用户账号
            //$url = url('sso/passport/create_bind_wechat');
            //header("location:$url");
            $user = new Users();
            $user_data['user_name'] = $_W['openid'] = $_fan['openid'];
            Cookie::set("openid_" . $this->site['id'], $_W['openid']);
            $user_data['site_id'] = $site_id;
            $user_data['user_crypt'] = random(6);
            switch ((int)$fans_info['sex']) {
                case 1:
                    $user_data['sex'] = '男';
                    break;
                case 2:
                    $user_data['sex'] = '女';
                    break;
                default:
                    $user_data['sex'] = '保密';
                    break;
            }

            $user_data['avatar'] = $fans_info['headimgurl'];
            $user_data['nickname'] = $fans_info['nickname'];

            $user_data['pass'] = "NOTSET"; //crypt_pass($user_data['password'], $user_data['user_crypt']);

            //默认用户状态
            $user_data['status'] = 99;
            //默认用户组
            $user_data['user_role_id'] = 4;
            $user_data['created'] = date("Y-m-d H:i:s");
            if ($fans_info['unionid']) {
                $user_data['wechat_unionid'] = $fans_info['unionid'];
            }
            $user_data = $user->setDefaultValueByFields($user_data, ['id', 'last_update']);
            //不存在用户
            if ($res = $user->allowField(true)->validate(true)->save($user_data)) {
                //注册成功以后 绑定
                $connect['user_id'] = $user['id'];
                $_W['wechat_fans_model']->where(['openid' => $fans_info['openid']])->update($connect);
                $user->log_user_in();
                $from_uid = $_W['refer'];
                if ($from_uid) {
                    // send data to
                    MhcmsDistribution::make_down_line($user['id'], $from_uid);
                }
            } else {
                test("对不起，用户注册失败 。" . $res['msg']);
            }
        }

        if ($uuid) {
            Cache::set("mhcms_wechat_login:" . $uuid, $user['id']);
            $this->message("登录成功", 1, "/");
        } else {
            if ($_W['site']['id'] == $site_id) {
                header('location:' . urldecode($forward));
                die();
                $this->message("登录成功", 1, urldecode($forward));
            } else {
                $this->message("登录成功", 1, $forward);
            }
        }
    }

    public function mhcms_wx_subscribe_login()
    {
        //todo check the user_id and login

        global $_W, $_GPC;
        $uuid = $_GPC['uuid'];
        $new_uuid = Cache::get("mhcms_wechat_subscribe_login:" . $uuid);

        if ($uuid && $uuid != $new_uuid && is_numeric($new_uuid)) {
            $user = Users::get(['id' => $new_uuid]);
            if ($user) {
                $status = "success";
                $user->log_user_in();
            }
        } elseif ($uuid == $new_uuid) {
            $status = "wait";
        } else {
            $status = "expired";
        }
        return [
            'status' => $status,
            'new_uuid' => $new_uuid,
            'uuid' => $uuid
        ];
    }

    public function wx_subscribe()
    {
        global $_W, $_GPC;

        $forward = input('param.forward', '');
        $forward = $forward ? $forward : Cookie::get('forward');


        if (strpos($forward, "login") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "register") !== false) {
            $forward = "/";
        }
        if (strpos($forward, "logout") !== false) {
            $forward = "/";
        }

        $to_site_id = input('param.site_id', 0);
        $to_site_id = $to_site_id ? $to_site_id : $_W['site']['id'];

        $api = MhcmsWechatEngine::create($_W['account']);
        $refer = "";
        if (isset($_W['refer']) && $_W['refer']) {
            $refer = "###" . $_W['refer'];
        }

        $barcode['action_info']['scene']['scene_str'] = $this->uuid . $refer;
        $barcode['action_name'] = "QR_STR_SCENE";
        $barcode['expire_seconds'] = 604800;
        $res = $api->barCodeCreateDisposable($barcode);

        $this->view->code = url('core/common_service/str_to_qr', ['str' => urlencode($res['url'])]);


        Cache::set("mhcms_wechat_subscribe_login:" . $this->uuid, $this->uuid);
        $login_url = url('sso/passport/mhcms_wx_subscribe_login', [], true, true);
        $this->view->mhcms_wx_login_api = $login_url;
        $this->view->post_data = ['uuid' => $this->uuid, 'forward' => urlencode($forward), 'to_site_id' => $to_site_id];
        $this->view->forward = urldecode($forward);
        $this->view->uuid = $this->uuid;

        $this->view->view = $this->view;
        return $this->view->fetch();
    }


    public function bind_wechat()
    {
        global $_W, $_GPC;
        $wechat = MhcmsWechatEngine::create($_W['account']);

        $fans_info = $wechat->oauth_user_info_login();
        $where['id'] = $fans_info['openid'];
        $_fan = $_W['wechat_fans_model']->where($where)->find();


        $new_fan = [];
        $new_fan['openid'] = $fans_info['openid'];
        $new_fan['avatar'] = $fans_info['headimgurl'];
        $new_fan['nickname'] = $fans_info['nickname'];
        $new_fan['subscribe'] = $fans_info['subscribe'];
        $new_fan['province'] = $fans_info['province'];
        $new_fan['city'] = $fans_info['city'];
        $new_fan['gender'] = $fans_info['sex'];
        $new_fan['country'] = $fans_info['country'];
        $new_fan['follow_time'] = date("Y-m-d H:i:s", $fans_info['subscribe_time']);


        if (!$_fan && $new_fan['openid']) {
            $new_fan = $_W['wechat_fans_model']->setDefaultValueByFields($new_fan, ['id']);
            $_W['wechat_fans_model']->insert($new_fan);
            $_fan = $new_fan;
        }

        if ($_fan && !$_fan['nickname']) {
            $_W['wechat_fans_model']->where(['id' => $_fan['id']])->update($new_fan);
        }

        if ($_fan['user_id']) {
            $user = Users::get(['id' => $_fan['user_id']]);
        }
        //先处理 unionid
        //union 处理失败 处理openid
        if (!$user) {
            //todo process unionid
            if ($fans_info['unionid']) {
                $union_where = [];
                $union_where['wechat_unionid'] = $fans_info['unionid'];
                // try to find the union user
                if ($user = Users::get($union_where)) {
                    $new_fan['user_id'] = $user['id'];
                    $_W['wechat_fans_model']->where(['id' => $_fan['id']])->update($new_fan);
                }
            } else {
                $new_fan['user_id'] = $this->user['id'];
                $_W['wechat_fans_model']->where(['id' => $_fan['id']])->update($new_fan);
                $this->error("绑定成功");
            }
        } else {
            $this->error("该微信已经绑定其他账号了");
        }

    }

    public function jump()
    {
        global $_W, $_GPC;
        $forward = input('param.forward', '');
        $to_site_id = input('param.to_site_id', 0);
        $this->view->forward = urldecode((urldecode($forward)));
        $this->view->site_id = $to_site_id;
        return $this->view->fetch();
    }

    public function create_bind_wechat()
    {
        global $_W, $_GPC;
        if ($this->isPost(true)) {
            $type = $_GPC['create_type'];
            switch ($type) {
                case "create":
                    //test if user exist

                    $data = $_GPC['data'];
                    $ret = [];
                    $foreword_url = "";
                    $code = 2;
                    $user_data['password'] = $data['password'];//) == input('param.password1') ? input('param.password') : $this->zbn_msg("两次密码必须一样", 2);;
                    $user_data['user_name'] = $data['user_name'];

                    //todo verify mobile code
                    $code_where['target'] = $user_data['user_name'];
                    $code_where['status'] = 0;
                    //$code_where['create_at'] = ['GT' , date("Y-m-d H:i:s" , strtotime("-300 seconds"))];
                    $res = set_model('sms_report')->where($code_where)->order('id desc')->find();
                    if (!$res) {
                        $ret['code'] = 3;
                        $ret['msg'] = "验证码错误！";
                        return $ret;
                    }


                    if (!is_phone($user_data['user_name'])) {
                        $ret['code'] = 3;
                        $ret['msg'] = "手机号码不正确！";
                        echo json_encode($ret);
                        return;
                    }

                    /**
                     *
                     * if (!$user_data['password']) {
                     * $ret['code'] = 3;
                     * $ret['msg'] = "请输入密码！";
                     * echo json_encode($ret);
                     * return;
                     * }
                     */

                    $user_data['password'] = ""; //随机密码

                    $connect_where = ['id' => $_W['openid']];

                    $connect = Db::name("sites_smallapp_fans_" . $this->small_app['id'])->where($connect_where)->find();
                    if (!$connect) {
                        $ret['code'] = 3;
                        $ret['msg'] = "对不起，非法操作请重新打开程序！";
                        echo json_encode($ret);
                        return;
                    }

                    if ($connect['user_id']) {
                        $ret['code'] = 3;
                        $ret['msg'] = "对不起，您已经绑定过用户了 无法再次绑定！";
                        echo json_encode($ret);
                        return;
                    }

                    $user = new Users();
                    $user_data['site_id'] = $_W['site']['id'];
                    $user_data['user_crypt'] = random(6);
                    $user_data['pass'] = "NOTSET"; //crypt_pass($user_data['password'], $user_data['user_crypt']);

                    $user_data['user_status'] = 1;
                    $user_data['status'] = 99;
                    $user_data['user_role_id'] = 2;
                    $user_data['created'] = date("Y-m-d H:i:s");

                    //todo avatar

                    //todo nickname

                    //todo gender

                    //todo ip


                    //$validate = Loader::validate('Users');
                    //$result = $validate->check($user_data);


                    $where['user_name'] = $user_data['user_name'];
                    /**
                     * 是否开启分站用户数据隔离
                     */
                    if ($_W['global_config']['split_user_data'] == 1) {
                        $where['site_id'] = $_W['site']['id'];
                    }
                    $user = Users::get($where);


                    //已存在用户
                    if ($user) {
                        $smallapp_fans = Db::name("sites_smallapp_fans_" . $this->small_app['id'])->where(['user_id' => $user['id']])->find();
                        if ($smallapp_fans) {
                            $ret['code'] = 1;
                            $ret['msg'] = "您输入的手机号码已经绑定过微信小程序了！";
                        } else {
                            //注册成功以后 绑定
                            $connect['user_id'] = $user['id'];
                            Db::name("sites_smallapp_fans_" . $this->small_app['id'])->where($connect_where)->update($connect);
                            $ret['code'] = 1;
                            $ret['msg'] = "绑定成功";
                            $ret_data['auth_str'] = $user->log_user_in();
                            $ret_data['user_id'] = $user['id'];
                            $ret['data'] = $ret_data;
                        }
                    } else {
                        $user = new Users();
                        //不存在用户
                        if ($res = $user->allowField(true)->validate(true)->save($user_data)) {
                            //注册成功以后 绑定
                            $connect['user_id'] = $user['id'];
                            Db::name("sites_smallapp_fans_" . $this->small_app['id'])->where($connect_where)->update($connect);

                            $ret_data['auth_str'] = $user->log_user_in();
                            $ret_data['user_id'] = $user['id'];

                            $ret['code'] = 1;
                            $ret['data'] = $ret_data;
                        } else {
                            $ret['code'] = 3;
                            $ret['msg'] = $res;
                        }
                    }
                    return $ret;


                    break;
                case "bind":


                    break;

            }

        } else {
            return $this->view->fetch();
        }

    }

    /**
     *
     */
    public function wx_app_login()
    {
        //TODO Login
        $user_info = input('param.userinfo');

        $where['weixin_id'] = $user_info['openid'];

        $wechat = new wechat($this->site_wechat);
        $base = $wechat->get_fans_info($user_info['openid']);


        if ($user = Users::get($where)) {
            $user->subscribe = $base['subscribe'];
            $user->save();
            $this->log_user_in($user);
            exit;
        }
        /*array(10) { ["openid"]=> string(28) "oxrg_xNteESsFCvXI5ayCuph5OsI" ["nickname"]=> string(12) "乐爱生活" ["sex"]=> int(1) ["language"]=> string(5) "zh_CN" ["city"]=> string(6) "镇江" ["province"]=> string(6) "江苏" ["country"]=> string(6) "中国" ["headimgurl"]=> string(142) "http://wx.qlogo.cn/mmopen/Q3auHgzwzM71YfTkRtMl8iaWvIhWtXRUMNNoiaEibdk9geKRfexCV0HSd8rTyPsicluH42Y25O2SpYRA10ibbMXWZd4RniaQX7jLgiaachW079s0dw/0" ["privilege"]=> array(0) { } ["unionid"]=> string(28) "ohuyKwZyxTG2isYkrrhLK4kJMYRI" }*/
        $user['user_crypt'] = random();
        $data['user_name'] = $user_info['openid'];
        $data['weixin_id'] = $user_info['openid'];
        $data['nickname'] = $user_info['nickname'];
        $data['avatar'] = $user_info['headimgurl'];
        $data['user_role_id'] = 12;
        $data['user_status'] = 1;
        $data['status'] = 99;
        $data['created'] = date("Y-m-d H:i:s");
        $data['subscribe'] = (int)$user_info['subscribe'];
        if ($user = Users::create($data)) {
            $this->log_user_in($user);
            header("location:/");
            exit;
        }
    }

    public function register_deal_info(){
        $this->view->fetch();
    }
}