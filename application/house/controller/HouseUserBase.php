<?php
// +----------------------------------------------------------------------
// | 鸣鹤CMS [ New Better  ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2017 http://www.mhcms.net All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( 您必须获取授权才能进行使用 )
// +----------------------------------------------------------------------
// | Author: new better <1620298436@qq.com>
// +----------------------------------------------------------------------
namespace app\house\controller;

use app\common\controller\HomeBase;
use app\common\controller\ModuleUserBase;
use app\common\controller\UserBase;
use app\core\util\ContentTag;

class HouseUserBase extends ModuleUserBase
{

    protected $cates;
    protected $have_children_cates;
    protected $agent;


    public function _initialize()
    {
        global $_W , $_GPC;
        parent::_initialize(); // TODO: Change the autogenerated stub
        //获取所有分类信息
        $cate_model = set_model("house_cate");
        $_cates = $cate_model->order('listorder desc')->where(['site_id' =>["IN" , [ $_W['root']['site_id'] , $_W['site']['id']  ]] ])->select()->toArray();

        $this->have_children_cates = [];

        foreach ($_cates as $_cate) {
            if ($_cate['parent_id'] > 0) {
                $this->have_children_cates[$_cate['id']] = $_cate;
            }
            if (isset($_GPC['cate_id']) && $_GPC['cate_id'] == $_cate['id']) {
                //点亮当前栏目
                $_cate['class'] = "active";
                //记录父栏目
                $active_parent_id = $_cate['parent_id'];
            }
            $this->cates[$_cate['id']] = $_cate;
        }
        //点亮当前父栏目
        if (isset($active_parent_id) && $active_parent_id) {
            $this->cates[$active_parent_id]['class'] = "active";
        }

        /**
         * 栏目导航
         */
        $this->view->cate_tree = $cate_tree = ContentTag::get_cate_tree([], 0, 0, $this->cates, 'house');
        $this->view->cate_nav = ContentTag::to_tree($cate_tree);

        //todo load agent
        $this->view->agent = $this->agent = set_model('house_agent')->where(['user_id'=>$this->user['id'] , 'status' => 99])->find();


        //todo mobile force
        //微信注册的user_name不是手机号
        // if($_W['module_config']['force_mobile'] && !is_phone($this->user['user_name'])){
        //     //
        //     $url = url('sso/passport/sso_login');
        //     header("location:$url");die();
        // }
    }
}