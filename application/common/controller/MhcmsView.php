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
namespace app\common\controller;

use app\wechat\util\WechatUtility;
use think\Exception;
use think\Hook;
use think\Log;
use think\View;

class MhcmsView extends View
{
    /**
     * 解析和获取模板内容 用于输出
     * @param string $template 模板文件名或者内容
     * @param array $vars 模板输出变量
     * @param array $replace 替换内容
     * @param array $config 模板参数
     * @param bool $renderContent 是否渲染内容
     * @return string
     * @throws \Exception
     */
    public function fetch($template = '', $vars = [], $replace = [], $config = [], $renderContent = false)
    {
        global $_W;
        // 模板变量
        $vars = array_merge(self::$var, $this->data, $vars ,['_W' => $_W , 'view' => $this]);

        // 页面缓存
        ob_start();
        ob_implicit_flush(0);

        // 渲染输出
        try {
            $method = $renderContent ? 'display' : 'fetch';
            $this->engine->$method($template, $vars, $config);
        } catch (\Exception $e) {
            ob_end_clean();
            WechatUtility::logging('error', $e->getMessage());
            if ($_W['debug']) {

                 throw $e;
            } else {

            }
        }
        // 获取并清空缓存
        $content = ob_get_clean();
        // 内容过滤标签
        Hook::listen('view_filter', $content);
        // 允许用户自定义模板的字符串替换
        $replace = array_merge($this->replace, $replace);
        if (!empty($replace)) {
            $content = strtr($content, $replace);
        }
        if($_W['pjax']){
            echo $content;
        }else{
            return $content;
        }
    }
}