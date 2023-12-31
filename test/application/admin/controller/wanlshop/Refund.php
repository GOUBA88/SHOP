<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Db;
use think\Exception;

use think\exception\PDOException;

/**
 * 订单退款管理
 *
 * @icon fa fa-circle-o
 */
class Refund extends Backend
{
    
    /**
     * Refund模型对象
     * @var \app\admin\model\wanlshop\Refund
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\Refund;
        $this->view->assign("expresstypeList", $this->model->getExpresstypeList());
        $this->view->assign("typeList", $this->model->getTypeList());
        $this->view->assign("reasonList", $this->model->getReasonList());
        $this->view->assign("stateList", $this->model->getStateList());
        $this->view->assign("statusList", $this->model->getStatusList());
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
        if ($this->request->isAjax())
        {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField'))
            {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                    ->with(['user','order','pay','shop'])
                    ->where($where)
                    ->order($sort, $order)
                    ->count();

            $list = $this->model
                    ->with(['user','order','pay','shop'])
                    ->where($where)
                    ->order($sort, $order)
                    ->limit($offset, $limit)
                    ->select();

            foreach ($list as $row) {
				if($row['order_type'] === 'groups'){
					$row->groupsgoods->visible(['title','image']);
				}else{
					$row->goods->visible(['title','image']);
				}
                $row->getRelation('user')->visible(['id']);
                $row->getRelation('order')->visible(['id']);
				$row->getRelation('pay')->visible(['pay_no']);
				$row->getRelation('shop')->visible(['shopname']);
            }
            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }
	
	/**
	 * 退款详情
	 */
	public function detail($ids = null)
	{
	    $row = $this->model->get($ids);
	    if (!$row) {
	        $this->error(__('No Results were found'));
	    }
		$row['images'] = explode(',', $row['images']);
		
		if($row['order_type'] === 'groups'){
			$row['ordergoods'] = model('app\admin\model\wanlshop\GroupsOrderGoods')
			->where('id', 'in', $row['goods_ids'])
			->select();
		}else{
			$row['ordergoods'] = model('app\admin\model\wanlshop\OrderGoods')
			    ->where('id', 'in', $row['goods_ids'])
			    ->select();
		}	
			
		$row['log'] = model('app\admin\model\wanlshop\RefundLog')
			->where(['refund_id' => $ids])
			->order('createtime desc')
			->select();
	    $this->view->assign("row", $row);
		return $this->view->fetch();
	}
	
	/**
	 * 同意退款
	 */
	public function agree($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		if ($row['state'] != 3) {
			$this->error(__('当前状态，不可操作'));
		}
		// 判断金额
		if(number_format($row['price'], 2) > number_format($row->pay->price, 2)){
			$this->error(__('非法退款金额，金额超过订单金额！！'));
		}
		// 退款类型
		$refundType = 2;
		$result = false;
		Db::startTrans();
		try {
			// 判断退款类型
			if($row['type'] == 0){
				// 退款完成
				$state = 4;
				// 退款日志
				$refundLog = '平台判定卖家需配合买家完成退货';
				$orderGoodsState = 3;
				// 返还资金，并写入日志，未收货前资金等于冻结在平台无需处理卖家资金流向
				controller('addons\wanlshop\library\WanlPay\WanlPay')->money(+$row['price'], $row['user_id'], '客服判定同意退款', 'refund', $row['pay']['order_no']);
				
				// 1.1.5升级 第三方退款
				$config = get_addon_config('wanlshop');
				// 检查是否原路返还
				if($config['config']['refund_switch'] == 'Y')
				{
					$refundType = 3;
					$wanlpay = controller('addons\wanlshop\library\WanlPay\WanlPay')->refund($row['id'], $row['price'], $row['order_pay_id']);
					// code=0同步方法：refund判断fund_change = Y，代表退款成功，支付宝文档《如何判断退款是否成功》，https://opendocs.alipay.com/support/01rawa
					if($wanlpay['code'] == 0)
					{
						// 退款中
						$state = 4; 
						// 退款日志
						$refundLog = '平台判定卖家需配合买家完成退货，已提交'.$wanlpay['data']['type_text'].'已将退款￥'.$wanlpay['data']['money'].'元原路返还买家'.$wanlpay['data']['type_text'].'账户';
						// 扣除本地用户余额
						controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
					// 如果退款成功,修改退款状态为第三方退款中，等待回调,否则退款到余额
					}else if($wanlpay['code'] == 200){
						// 退款中
						$state = 7; 
						// 退款日志
						$refundLog = '平台判定卖家需配合买家完成退货，已提交'.$wanlpay['data']['type_text'].'处理，预计24小时内将退款'.$wanlpay['data']['money'].'元原路返还给买家'.$wanlpay['data']['type_text'].'账户';
						// 扣除本地用户余额
						controller('addons\wanlshop\library\WanlPay\WanlPay')->money(-$wanlpay['data']['money'], $wanlpay['data']['user_id'], '退款订单（订单号：'.$order['order_no'].'）已原路返回余额至你的'.$wanlpay['data']['type_text'], 'sys');
					// 1.1.6升级
					}else if($wanlpay['code'] == 1){
						$refundLog = '平台判定卖家需配合买家完成退货，'.$row['price'].'元退款到买家账号余额';	
					}else{
						$refundLog = '平台判定卖家需配合买家完成退货，第三方支付退款失败已转入余额，请手动提现';
					}
				}
				//后续版本推送订购单
				// ...
			}else if($row['type'] == 1){
				// 先同意退款，还需要买家继续退货
				$state = 1;
				$orderGoodsState = 2;
			}else{
				$this->error(__('非法退款类型'));
			}
			// 只有本地余额退款状态4,和先同意退款,需要商家继续退款状态1执行,状态7原路返回在回调更新状态
			// 退款状态:0=申请退款,1=卖家同意,2=卖家拒绝,3=申请平台介入,4=成功退款,5=退款已关闭,6=已提交物流,7=第三方退款中,8=退款失败
			if($state == 1 || $state == 4){
				// 更新商品状态
				$this->setOrderGoodsState($orderGoodsState, $row['goods_ids'], $row['order_type']);
				// 更新订单状态
				$this->setRefundState($row['order_id'], $row['order_type']);
			}
			// 写入日志
			$this->refundLog($row['user_id'], $row['id'], $refundLog, $refundType);
			// 更新退款
			$result = $row->allowField(true)->save([
				'state' => $state,
				'completetime' => time()
			]);
			
		    Db::commit();
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
		    $this->error(__('No rows were updated'));
		}
	}
	
	/**
	 * 平台判定拒绝退款
	 */
	public function refuse($ids = null)
	{
		$row = $this->model->get($ids);
		if (!$row) {
		    $this->error(__('No Results were found'));
		}
		if ($row['state'] != 3) {
			$this->error(__('当前状态，不可操作'));
		}
		if ($this->request->isPost()) {
		    $params = $this->request->post("row/a");
		    if ($params) {
		        $result = false;
		        Db::startTrans();
		        try {
					// 写入日志
					$this->refundLog($row['user_id'], $row['id'], '客服判定：'.$params['refund_content']);
					// 更新商品状态
					$this->setOrderGoodsState(4, $row['goods_ids'], $row['order_type']);
					// 更新订单状态
					$this->setRefundState($row['order_id'], $row['order_type']);
					// 更新退款
					$result = $row->allowField(true)->save(['state' => 5]);
					
		            Db::commit();
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
		            $this->error(__('No rows were updated'));
		        }
		    }
		    $this->error(__('Parameter %s can not be empty', ''));
		}
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}
	
	/**
	 * 更新订单商品状态（方法内使用）1.0.5升级
	 *
	 * @ApiSummary  (WanlShop 更新订单商品状态)
	 * @ApiMethod   (POST)
	 * 
	 * @param string $status 状态
	 * @param string $goods_id 商品ID
	 */
	private function setOrderGoodsState($status = 0, $goods_id = 0, $order_type = 'goods')
	{
		if($order_type === 'groups'){
			$orderGoodsModel = model('app\admin\model\wanlshop\GroupsOrderGoods');
		}else{
			$orderGoodsModel = model('app\admin\model\wanlshop\OrderGoods');
		}
		return $orderGoodsModel->save(['refund_status' => $status],['id' => $goods_id]);
	}
	
	/**
	 * 修改订单状态（方法内使用） 1.0.5升级
	 *
	 * @ApiSummary  (WanlShop 修改订单状态)
	 * @ApiMethod   (POST)
	 * 
	 * @param string $id 订单ID
	 */
	private function setRefundState($order_id = 0, $order_type = 'goods')
	{
		if($order_type === 'groups'){
			$orderModel = model('app\admin\model\wanlshop\GroupsOrder');
			$orderGoodsModel = model('app\admin\model\wanlshop\GroupsOrderGoods');
		}else{
			$orderModel = model('app\admin\model\wanlshop\Order');
			$orderGoodsModel = model('app\admin\model\wanlshop\OrderGoods');
		}
		$list = $orderGoodsModel
			->where(['order_id' => $order_id])
			->select();
		$refundStatusCount = 0;
		foreach($list as $row){
			// 退款状态:0=未退款,1=退款中,2=待退货,3=退款完成,4=退款关闭,5=退款被拒
			if($row['refund_status'] == 3) $refundStatusCount += 1;
		}
		// 订单状态:1=待支付,2=待发货,3=待收货,4=待评论,5=售后订单(已弃用),6=已完成,7=已取消 1.0.5升级
		// 如果订单下所有商品全部退款完毕则关闭订单
		if(count($list) == $refundStatusCount){
			$orderModel->save(['state'  => 7],['id' => $order_id]);
			return true;
		}
		return false;
	}
	
	/**
	 * 退款日志（方法内使用）
	 *
	 * @ApiSummary  (WanlShop 退款日志)
	 * 
	 * @param string $user_id 用户ID
	 * @param string $refund_id 退款ID
	 * @param string $content 日志内容
	 * @param string $type 退款状态:0=买家,1=卖家,2=官方,3=系统
	 */
	private function refundLog($user_id = 0, $refund_id = 0, $content = '', $type = 2)
	{
		return model('app\admin\model\wanlshop\RefundLog')->allowField(true)->save([
			'user_id' => $user_id,
			'refund_id' => $refund_id,
			'type' => $type,
			'content' => $content
		]);
	}
}