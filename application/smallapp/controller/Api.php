<?php
namespace app\smallapp\controller;

use app\common\controller\Base;
use app\common\controller\UserBase;
use app\common\model\NodeHits;
use app\common\model\NodeIndex;

use app\common\model\Sites;
use app\common\model\SitesWechat;
use app\common\model\Users;
use app\order\model\Orders;
use app\pay\payment\micropay\JsApiPay;
use app\pay\payment\micropay\utils\WxPayApi;
use app\pay\payment\micropay\utils\WxPayConfig;
use app\pay\payment\micropay\utils\WxPayDataBase;
use app\pay\payment\micropay\utils\WxPayUnifiedOrder;
use app\sso\controller\Passport;
use think\Cache;
use think\Db;

/**
 * @property int node_type_id
 */
class Api extends SmallApp
{
    public $fans;

    /**
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function _initialize()
    {
        global $_W , $_GPC;
        parent::_initialize(); // TODO: Change the autogenerated stub
        if(!$this->user){
            die(50003);
        }
    }

    /**
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function update_user(){
        global $_W , $_GPC;
        if(empty($this->user['nickname'])){
            //update
            $this->user->nickname = $_GPC['nickname'];
            $this->user->avatar = $_GPC['headimgurl'];
            $this->user->sex = $_GPC['sex'];
            $this->user->save();
        }

        if(empty($this->fans['nickname'])){
            //update
            $this->fans['nickname'] = $_GPC['nickname'];
            $this->fans['province'] = $_GPC['province'];
            $this->fans['city'] = $_GPC['city'];
            $this->fans['country'] = $_GPC['country'];
            $this->fans['gender'] = $_GPC['gender'];
            $this->fans['nickname'] = $_GPC['nickname'];
            $this->fans['avatar'] = $_GPC['headimgurl'];
            Db::name('sites_smallapp_fans_' . $this->small_app['id'])->where(['id'=>$_GPC['utoken']])->update($this->fans);
        }

        $ret['code'] = 1;
        $ret['data'] = $this->user;
        return $ret;
    }

    public function get_wxpay_params(){
        global $_W, $_GPC;
        $query = $_GPC['query'];
        if(!is_array($query)){
            $query = mhcms_json_decode($query);
        }

        $_W['pay_mode'] = "WX_XCX";
        $_W['WxPayConfig'] =   WxPayConfig::get_mini_config();

        $order_id = (int)$query['order_id'];
        new WxPayDataBase();
        $order = Orders::get(['id'=>$order_id]);
        if(!$order){
            //$this->message("对不起订单不存在！");
            $ret['code'] = 0;
            $ret['msg'] = "对不起订单不存在！";

            echo json_encode($ret);exit();
        }
        if($order->buyer_user_id !=$this->user['id']){
            $order->pay_user_id = $this->user['id'];
        }
        $order->pay_mode = "WX_XCX";
        $order->save();

        $tools = new JsApiPay();
        $openId =$_W['fans']['id'];
        //②、统一下单
        $input = new WxPayUnifiedOrder();
        $input->SetAppid($_W['WxPayConfig']['app_id']);
        $input->SetBody($order['note'] . '.');
        $input->SetNonce_str(random(32)); //nonce_str

        $input->SetOut_trade_no($order['trade_sn']);//out_trade_no
        $input->SetTotal_fee($order['total_fee'] * 100);//total_fee
        $input->SetTime_start(date("YmdHis"));
        $input->SetMch_id($_W['WxPayConfig']['mchid']);
        //$input->SetSubMchid($order['sub_mch_id']);
        $input->SetTime_expire(date("YmdHis", time() + 600));
        $input->SetGoods_tag($order['note'] . ".");
        $input->SetNotify_url(\think\Url::build("pay/api/call_back", ['gateway' => 'micropay'], "", true)); //notify_url

        //pay/api/call_back/gateway/micropay.html
        $input->SetTrade_type("JSAPI");//trade_type
        //
        //$input->SetSubOpenid($openId);
        $input->SetOpenid($openId);
        // 过滤post数组中的非数据表字段数据
        $input->SetAttach($order_id);//原样返还
        $_order = WxPayApi::unifiedOrder($input);
        $jsApiParameters = $tools->GetJsApiParameters($_order);
        //获取共享收货地址js函数参数
        //$editAddress = $tools->GetEditAddressParameters();
        //$this->view->editAddress = $editAddress;
        $pay_param = $jsApiParameters;
        $ret_data = mhcms_json_decode($jsApiParameters) ;
        if(is_array($ret_data)){
            $ret['code'] = 1;
            $ret['data'] =$ret_data;
        }else{
            $ret['code'] = 0;
            $ret['data'] =$jsApiParameters;
        }


        echo json_encode($ret);die();
    }
}