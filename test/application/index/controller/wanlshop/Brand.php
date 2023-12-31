<?php

namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;

use think\Db;
use think\Exception;
use think\exception\PDOException;
use think\exception\ValidateException;

use fast\Tree;
/**
 * 品牌管理
 *
 * @icon fa fa-circle-o
 */
class Brand extends Wanlshop
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    /**
     * Brand模型对象
     * @var \app\index\model\wanlshop\Brand
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\index\model\wanlshop\Brand;
		$tree = Tree::instance();
		$tree->init(collection(model('app\index\model\wanlshop\Category')->where('type','goods')->order('weigh desc,id desc')->select())->toArray(), 'pid');
		$category = $tree->getTreeList($tree->getTreeArray(0), 'name');
		$this->view->assign("category", $category);
		$this->view->assign("statusList", $this->model->getStatusList());
		$this->view->assign("stateList", $this->model->getStateList());
    }
    
    /**
     * 查看
     */
    public function index()
    {
        //当前是否为关联查询
        $this->relationSearch = true;
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                    ->with(['category'])
                    ->where($where)
                    ->order($sort, $order)
                    ->count();
    
            $list = $this->model
                    ->with(['category'])
                    ->where($where)
                    ->order($sort, $order)
                    ->limit($offset, $limit)
                    ->select();
    
            foreach ($list as $row) {
                $row->getRelation('category')->visible(['name']);
            }
            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);
    
            return json($result);
        }
        return $this->view->fetch();
    }
	
	/**
	 * 查看
	 */
	public function selectpage()
	{
	    //设置过滤方法
	    $this->request->filter(['strip_tags', 'trim']);
	    if ($this->request->isAjax()) {
			// 1.0.6升级
			$keyValue = $this->request->post("keyValue");
			if($keyValue) $where['id'] = $keyValue;
			$where['state'] = 1;
	        $list = $this->model
				->where($where)
				->select();
	        $list = collection($list)->toArray();
	        $result = array("total" => count($list), "rows" => $list);
	        return json($result);
	    }
	}
	
	
	/**
	 * 添加
	 */
	public function add()
	{
		//设置过滤方法
		$this->request->filter(['strip_tags', 'trim']);
	    if ($this->request->isPost()) {
	        $params = $this->request->post("row/a");
	        if ($params) {
	            $params['shop_id'] = $this->shop->id;
	            $result = false;
	            Db::startTrans();
	            try {
					$params['state'] = 0;
	                $result = $this->model->allowField(true)->save($params);
	                Db::commit();
	            } catch (ValidateException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (PDOException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (Exception $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            }
	            if ($result !== false) {
	                $this->success();
	            } else {
	                $this->error(__('No rows were inserted'));
	            }
	        }
	        $this->error(__('Parameter %s can not be empty', ''));
	    }
	    return $this->view->fetch();
	}
	
	/**
	 * 编辑
	 */
	public function edit($ids = null)
	{
	    $row = $this->model->get($ids);
	    if (!$row) {
	        $this->error(__('No Results were found'));
	    }
	    if ($row['shop_id'] !=$this->shop->id) {
	        $this->error(__('You have no permission'));
	    }
	    if ($this->request->isPost()) {
	        $params = $this->request->post("row/a");
	        if ($params) {
	            $result = false;
	            Db::startTrans();
	            try {
					$params['state'] = 0;
	                $result = $row->allowField(true)->save($params);
	                Db::commit();
	            } catch (ValidateException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (PDOException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (Exception $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            }
	            if ($result !== false) {
	                $this->success();
	            } else {
	                $this->error(__('No rows were inserted'));
	            }
	        }
	        $this->error(__('Parameter %s can not be empty', ''));
	    }
	    $this->view->assign("row", $row);
	    return $this->view->fetch();
	}
	
}
