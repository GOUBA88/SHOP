<?php

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use fast\Tree;
/**
 * WanlShop店铺接口
 */
class Shop extends Api
{
    protected $noNeedLogin = ['getShopInfo'];
    protected $noNeedRight = ['*'];
	
	public function _initialize()
	{
	    parent::_initialize();
		$this->model = model('app\api\model\wanlshop\Shop');
	}
	
	/**
	 * 一次性获取店铺相关数据 1.0.8升级
	 *
	 * @ApiSummary  (WanlShop 一次性获取店铺相关数据)
	 * @ApiMethod   (GET)
	 *
	 * @param string $id 页面ID
	 */
	public function getShopInfo($id = null)
	{
		//设置过滤方法
		$this->request->filter(['strip_tags']);
		// 获取店铺信息
		$row = $this->model->get($id);
		if (!$row) {
		    $this->error(__('未找到此商家'));
		}
		// 获取商家类目
		$tree = Tree::instance();
		$tree->init(
			model('app\api\model\wanlshop\ShopSort')
				->where(['shop_id' => $row['id']])
				->field('id, pid, name, image')
				->order('weigh asc')
				->select()
		);
		$row['category'] = $tree->getTreeArray(0);
		// 查看是否被关注
		$row['isFollow'] = model('app\api\model\wanlshop\find\Follow')
			->where([
				'user_no' => $row['find_user']['user_no'], 
				'user_id' => $this->auth->id
			])
			->count();
		$row['isLive'] = model('app\api\model\wanlshop\Live')
			->where(['shop_id' => $row['id'], 'state' => 1])
			->field('id')
			->find();
		// 获取类目样式配置
		$shopConfig = model('app\api\model\wanlshop\ShopConfig')
			->where(['shop_id' => $row['id']])
			->find();
		$row['categoryStyle'] = (int)$shopConfig['category_style'];
		// 获取商家自定义页面
		$row['page'] = model('app\api\model\wanlshop\Page')
			->where([
				'shop_id' => $row['id'], 
				'type' => 'shop'
			])
			->field('id, name, page, item')
			->find();
		$this->success('返回成功', $row);
	}
	
	
}