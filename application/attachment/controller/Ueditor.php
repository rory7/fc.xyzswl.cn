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
namespace app\attachment\controller;
use app\common\controller\Base;
use app\common\controller\UserBase;
use app\common\model\File;
use app\common\util\file\Uploader;
use app\common\util\forms\input;
class Ueditor extends Base {
	public $save_dir, $file_model;
    /**
     *
     */
    public function _initialize() {
		parent::_initialize();
		// TODO: Change the autogenerated stub
		$this->file_model = new File();
        if(!$this->user){
            $this->user = check_admin();
            $this->user_id = $this->user->id;
        }
		$this->save_dir   = "upload_file/" . $this->user_id . "/";
	}

	/**
	 *http://www.jjw.bz/static/plugins/ueditor/php/controller.php?action=listimage&start=0&size=20&noCache=1476713533518
	 */
	public function index() {
		$action = input( 'param.action' );
		switch ( $action ) {
			case "config" :
				$result = $this->config();
				break;
			case "uploadimage" :
			case "uploadscrawl" :
			case 'uploadvideo':
				/* 上传文件 */
			case 'uploadfile':
				$result = $this->action_upload();
				break;
            case "upload_avatar" :
                $result = $this->avatar_upload();
                break;
			/* 列出图片 */
			case 'listimage':
				$result = $this->listimage();
				break;
			/* 列出文件 */
			case 'listfile':
				$result = $this->listfile();
				break;
			/* 抓取远程文件 */
			case 'catchimage':
				$result = $this->catchimage();
				break;
			default:
				$result = json_encode( array( 'state' => '请求地址出错' ) );
				break;
		}
		echo json_encode( $result );
	}

	/**
	 *
	 */
	private function config() {
	    global $_W;
	    $config_str =  preg_replace( "/\/\*[\s\S]+?\*\//", "", file_get_contents( SYS_PATH .  "statics/components/ueditor/php/config.json" ));

	    //图片前缀
        $prefix = $_W['global_config']['attach']['prefix'] ? $_W['global_config']['attach']['prefix'] :  $_W['siteroot'];
        $config_str = str_replace("{imageUrlPrefix}" , $prefix ,$config_str );

		$CONFIG = json_decode( $config_str, true );
		return $CONFIG;
	}

	private function listimage() {
	}

	/**抓取远程图片
	 * @return array
	 */
	private function catchimage() {
		$CONFIG = $this->config();
		set_time_limit( 0 );
		/* 上传配置 */
		$config    = array(
			"pathFormat" => $CONFIG['catcherPathFormat'],
			"maxSize"    => $CONFIG['catcherMaxSize'],
			"allowFiles" => $CONFIG['catcherAllowFiles'],
			"oriName"    => "remote.png",
			"user_id"    => $this->user_id
		);
		$fieldName = $CONFIG['catcherFieldName'];
		/* 抓取远程图片 */
		$list = array();
		if ( isset( $_POST[ $fieldName ] ) ) {
			$source = $_POST[ $fieldName ];
		} else {
			$source = $_GET[ $fieldName ];
		}
		foreach ( $source as $imgUrl ) {

			$item = new Uploader( $imgUrl, $config, "remote" );


            $info = $item->getFileInfo();
            $t_img =  array(
                "state"    => $info["state"],
                "url"      => $info["url"],
                "size"     => $info["size"],
                "title"    => htmlspecialchars( $info["title"] ),
                "original" => htmlspecialchars( $info["original"] ),
                "source"   => htmlspecialchars( $imgUrl )
            ) ;
            if ( $t_img ) {
                $data['state']    = "SUCCESS";
                $data['md5']      = file_exists(SYS_PATH . $t_img["url"]) ? md5_file(SYS_PATH . $t_img["url"]) : "";//
                $data['user_id']  = $this->user_id;
                $data['filename'] = htmlspecialchars( $t_img["original"]);
                $data['url']      = $t_img["url"];

                $data['filemime'] = "image/*";
                $data['filesize'] = $t_img["size"];
                $data['created']  = SYS_TIME;
                if($data['md5']){
                    if($test = $this->file_model->where(['md5' => $data['md5']])->find()){
                        $t_img['original'] =  $test['file_id'];
                        $t_img['url'] =  $test['url'];
                        @unlink(SYS_PATH . $info["url"]);//delete the file that just uploaded!
                    }else{
                        $t_img['original'] =  $this->file_model->isUpdate( false )->allowField(true)->save( $data );
                    }

                }
            }
			array_push( $list , $t_img );
        }
		/* 返回抓取数据 */
		return array(
			'state' => count( $list ) ? 'SUCCESS' : 'ERROR',
			'list'  => $list
		);
	}

	/**
     * 上传文件 核对md5
	 * 得到上传文件所对应的各个参数,数组结构
     *
	 */
	private function action_upload() {
		// Get a file name
		$file         = request()->file( 'upfile' );
		$file_info    = $file->getInfo();
		$org_fileName = $file_info['name'];
		$hash         = md5_file( $file_info['tmp_name'] );
		if ( $hash ) {
			$where["md5"] = $hash;
			$file_attach  = $this->file_model->where( $where )->find();
		}
		if ( isset( $file_attach ) ) {
			//found
			$data_ret['state']    = "SUCCESS";
			$data_ret['url']      = str_replace( "\\", "/", $file_attach['url'] );
			$data_ret['title']    = $file_attach['alt'];
			$data_ret['original'] = $file_attach['filename'];
			$data_ret['type']     = $file_attach['filemime'];
			$data_ret['size']     = $file_attach['filesize'];
			return $data_ret;
		} else {
			//not  found
			$uploaded = $file->move( $this->save_dir );
			if ( $uploaded ) {
				$data['state']    = "SUCCESS";
				$data['md5']      = $uploaded->hash( 'md5' );//
				$data['user_id']  = $this->user_id;
				$data['filename'] = $org_fileName;
				$data['url']      = str_replace( "\\", "/", $this->save_dir . $uploaded->getSaveName() );
				$data['filemime'] = $uploaded->getMime();
				$data['filesize'] = $uploaded->getSize();
				$data['created']  = SYS_TIME;
			}
			if ( $this->file_model->isUpdate( false )->allowField(true)->save( $data ) ) {
                $data['url'] = $data['url'];
				return $data;
			} else {
				return false;
			}
		}
	}

    /**
     * {
     * "code":0
     * ,"msg":""
     * ,"url":"http://cdn.abc.com/123.jpg"
     * }
     * @return bool
     */
    private function avatar_upload() {
        $CONFIG = $this->config();
        set_time_limit( 0 );
        /* 上传配置 */
        $config    = array(
            "pathFormat" => $CONFIG['avatarPathFormat'],
            "maxSize"    => $CONFIG['catcherMaxSize'],
            "allowFiles" => $CONFIG['catcherAllowFiles'],
            "oriName"    => "remote.png",
            "user_id"    => $this->user_id
        );
        $fieldName = $CONFIG['avatarFieldName'];

        $up = new Uploader($fieldName, $config);

        $file_info = $up->getFileInfo();
        /* 返回抓取数据 */
        $this->user->avatar = $file_info['url'] . "?t=".mt_rand(1,10000);
        $this->user->save();
        return $file_info;
    }
}