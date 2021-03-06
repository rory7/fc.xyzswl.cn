<?php

namespace app\member\controller;

use app\common\controller\ModuleUserBase;
use app\common\controller\UserBase;
use app\common\model\Draw;
use app\common\model\Node;
use app\common\model\NodeIndex;
use app\common\model\NodeTypes;
use app\common\model\PaymentLogs;
use app\common\model\UserRoles;
use app\common\model\Users;
use app\common\util\forms\input;
use app\common\util\Money;
use app\core\util\MhcmsDistribution;
use app\sms\model\Notice;

class Distribute extends ModuleUserBase
{
    public function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        global $_W, $_GPC;
        if ($_W['distribute']['status'] != 1) {
            $this->message("对不起,管理员未开启该功能");
        }
    }


    public function apply()
    {
        global $_W, $_GPC;

        $model = set_model('distribute_user');

        $distribute_user = $model->where(['user_id' => $this->user['id']])->find();
        if ($distribute_user && $distribute_user['status'] == 99) {
            $this->message("您已经是分销商了,无需再次申请!");
        }
        if ($distribute_user && $distribute_user['status'] == 0) {
            $this->message("您已经申请过了,请等待管理员审核!");
        }

        if ($this->isPost(true)) {
            if (isset($_GPC['_form_manual'])) {
                //手动处理数据
                $base_info = $_GPC;
            } else {
                //自动获取data分组数据
                $base_info = input('post.data/a');//get the base info
            }
            if ($distribute_user) {
                //update
                $this->message("您已经是分销商了,无需再次申请!");
            } else {


                $res = MhcmsDistribution::apply(0, $base_info);
                if ($res['code'] == 1) {
                    return $this->zbn_msg($res['msg'], 1, 'true', 1000, "'/member/distribute/link'", "''");
                } else {
                    return $this->zbn_msg($res['msg'], 2);
                }
            }

        } else {


            $this->view->fields = $model->model_info->get_user_publish_fields($distribute_user);
            return $this->view->fetch();
        }


    }

    //我的下级
    public function downline()
    {

        global $_W;
        $where = [];
        if (!$_W['global_config']['group']['share_account']) {
            $where['site_id'] = $_W['site']['id'];
        }
        $where['parent_id'] = $this->user_id;
        $lists = set_model("users")->field("id ,user_name,created , nickname")->where($where)->paginate();
        $this->view->users = $lists;
        return $this->view->fetch();
    }

    public function link()
    {
        global $_W;
        //todo check if the user is a distributerjs_api_list
        $where = [];
        $where['user_id'] = $this->user['id'];

        $distributer = set_model('distribute_user')->where($where)->find();

        if (!$distributer) {
            $msg_data['buttons'] = [];

            $msg_data['type'] = "confirm";
            $button = [
                'text' => "立刻申请分销商",
                'url' => url('member/distribute/apply')
            ];
            $msg_data['buttons']['second'] = $button;
            $msg_data['buttons']['main'] = [
                'text' => "返回",
                'url' => HTTP_REFERER
            ];

            $this->message(" 您还不是分销商,无法查看该功能！", 2, HTTP_REFERER, $msg_data);
        }


        //is a
        if ($distributer['status'] == 99) {
            //todo create or get personal link
            $where = [];
            $where['user_id'] = $this->user['id'];
            $where['url'] = url("/" . $_W['root']['default_app'], [], true, true);
            $share = set_model("share")->where($where)->find();

            if (!$share) {
                $where['site_id'] = $_W['site']['id'];
                $where['create_at'] = date("Y-m-d H:i:s");
                $where['module'] = $_W['root']['default_app'];
                $share['id'] = set_model("share")->insert($where, false, true);
            }

            $this->view->share = $share;

            return $this->view->fetch();
        } else {

            $msg_data['buttons'] = [];

            $msg_data['type'] = "confirm";
            $button = [
                'text' => "立刻申请分销商",
                'url' => url('member/distribute/apply')
            ];
            $msg_data['buttons']['second'] = $button;
            $msg_data['buttons']['main'] = [
                'text' => "返回首页",
                'url' => "/"
            ];
            $this->message(" 您还不是分销商,无法查看该功能！", 2, HTTP_REFERER, $msg_data);

        }

    }

    public function logs($type)
    {
        return $this->view->fetch();
    }

    public function orders($module)
    {
        return $this->view->fetch();
    }
}