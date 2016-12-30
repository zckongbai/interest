<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
class Floor
{
	protected $task_worker_num;
	protected $worker_num;
	protected $free_table;
	protected $map_table;	// fd 和 task 的对应关系

	protected $busy_table;	
	protected $wait_queue = array(); // 等待队列

	protected $port;
	protected $serv;
	private $pdo = null;
	protected $request_cnt;

	protected $elevator_config;

	function __construct(array $config) {
		$this->elevator_config = require_once("./elevator_config.php");
		$this->port = isset($config['port']) ? $config['port'] : 9509;
		$this->worker_num = isset($config['worker_num']) ? $config['worker_num'] : 1;

		// 任务队列
		$this->elevator_table = new swoole_table(1024);
		$this->elevator_table->column("elevator_num", swoole_table::TYPE_INT);
		$this->elevator_table->column("status", swoole_table::TYPE_STRING, 64);
		$this->elevator_table->column("now", swoole_table::TYPE_INT);
		$this->elevator_table->column("go", swoole_table::TYPE_STRING, 1000);
		$this->elevator_table->create();

		foreach ($this->elevator_config['elevator_num'] as $value) {
 			// $this->elevator_table->set($value, array("elevator_num"=>$value, "status"=>$this->elevator_config['status'], "now"=>$this->elevator_config['now'], "go"=>json_encode(array())));
 			$this->elevatorInit($value, $this->elevator_config);
		}
 	} 

 	public function run() {
 		// $this->serv = new swoole_server("127.0.0.1", $this->port);
 		$this->serv = new swoole_websocket_server("127.0.0.1", $this->port);
 		$this->serv->set(array(
 			"worker_num" => $this->worker_num,
 			"max_request" => 0,
 			"log_file" => "./floor.log",
 			"dispatch_mode" => 2,
 			));

        $this->serv->on('start', array($this, 'onStart'));
        $this->serv->on('open', array($this, 'onOpen'));
        $this->serv->on('message' , array( $this , 'onMessage'));
        $this->serv->on('close' , array( $this , 'onClose'));

        $this->serv->start();
 	}

 	public function onStart() {
 		echo "sys start \n";
 	}

 	public function onOpen(swoole_websocket_server $serv, $request) {
 		$fd = $request->fd;
        echo "client onOpen| fd = $fd \n";
 		// 链接上系统时初始化
 		// if (!$this->elevatorInit($request->fd, $this->elevator_config)) {
 		// 	echo "elevatorInit false";
 		// 	return false;
 		// }
 		// $tick_id = swoole_timer_tick(1000, array($this, "go"), $fd);
 		// $this->tick_id = $tick_id;
 	}

    public function onClose($serv, $fd) {
        echo "client {$fd} closed\n";
    }

	public function onMessage(swoole_websocket_server $serv, $frame) {
 		$fd = $frame->fd;
 		$data = json_decode($frame->data, true);
        // echo "client onMessage| fd = $fd \n";
 		if (!$this->addFloor($data['elevator_num'], $data['now'], $data['want'], $data['status'])) {
 			$msg = "addFloor false";
 			return $serv->push($fd, $msg);
 		}
 		echo "fd={$fd}, elevator_num={$data['elevator_num']}\n";
 		$elevator_num = $data['elevator_num'];
 		$tick_id = swoole_timer_tick( 1000, function() use ($fd, $elevator_num) {
 			$this->go($fd, $elevator_num);
 		} );
 		// $tick_id = swoole_timer_tick( 1000, array($this, "go"), array($fd, $elevator_num) );
 		$this->tick_id = $tick_id;
		$msg = "addFloor ok";
		return $serv->push($fd, $msg);
 	}

 	public function elevatorInit($elevator_num, $config) {
 		if (!$elevator_num || !is_array($config)) {
 			return false;
 		}
 		// 任务队列初始化
 		$this->elevator_table->set($elevator_num, array("elevator_num"=>$elevator_num, "status"=>$config['status'], "now"=>$config['now'], "go"=>json_encode(array())));
 	}

 	// 添加任务
 	/**
 	*	$floor_now 当前楼层
 	*	$floor_want 要去楼层
 	*	$status 升降 up | down | click
 	*
 	*/
 	public function addFloor($elevator_num, $floor_now, $floor_want="", $status="") {
 		$elevator = $this->elevator_table->get($elevator_num);
 		if (!$elevator) {
 			return false;
 		}
 		// 如果是空闲
 		if ($elevator['status'] == "free") {
 			// 门外按的上下
 			if (empty($floor_want) && in_array($status, array("up", "down"))) {
 				$elevator['status'] = $floor_now >= $elevator['now'] ? "up" : "down";
 			} else {
 				$elevator['status'] = $floor_now >= $elevator['now'] ? "up" : "down";
 			}

 		}

 		$go = json_decode($elevator['go'], true);
		$want_info = array('floor_now'=>$floor_now, 'floor_want'=>$floor_want, 'status'=>$status);
		$go[] = $want_info;
		$res = $this->elevator_table->set($elevator_num, array("elevator_num"=>$elevator_num, "status"=>$elevator["status"], "now"=>$elevator["now"], "go"=>json_encode($go)));
		return $res;
   	}
 	/**
 	* Int $floor_now = 1,2,3..
 	* Int $floor_want = 1,2,3 || ""
 	* String $status = up || down || click
 	*/
 	public function openDoor($elevator_num, $floor_now, $floor_want, $status="click") {
 		$this->addFloor($elevator_num, $floor_now, $floor_want, $status); 
 	}

 	public function closeDoor($elevator_num, $floor_now, $floor_want, $status="click") {
 		$this->delFloor($elevator_num, $floor_now, $floor_want, $status);
 	}

 	public function readFloor($elevator_num, $floor_num) {

 	}

 	public function delFloor($elevator_num, $floor_now, $floor_want="", $status="") {
 		$elevator = $this->elevator_table->get($elevator_num);
 		if (!$elevator) {
 			return false;
 		}
 		$go = json_decode($elevator['go'], true);
 		$del_floor = array('floor_now'=>$floor_now, 'floor_want'=>$floor_want, 'status'=>$status);
 		if (false !== ($index = array_search($del_floor, $go))) {
 			unset($go[$index]);
			return $this->elevator_table->set($elevator_num, array("elevator_num"=>$elevator_num, "status"=>$elevator['status'], "now"=>$elevator['now'], "go"=>json_encode($go)));
 		}
 		return false;
 	}

 	public function statusChange($elevator_num, $status="", $now="") {
 		$elevator = $this->elevator_table->get($elevator_num);
 		if (!$elevator) {
 			return false;
 		}
 		if ($status) {
 			$elevator['status'] = $status;
 		}
 		if ($now) {
 			$elevator['now'] = $now;
 		}
 		return $this->elevator_table->set($elevator_num, $elevator);
 	}

 	public function go($fd, $elevator_num) {
 		// echo " go is run| elevator_num = {$elevator_num} \n";
 		$elevator = $this->elevator_table->get($elevator_num);
 		var_dump($elevator);
 		if (!$elevator) {
 			echo "elevator get false\n";
 			// 清除任务, 更改状态
 			if ($this->tick_id){
 				swoole_timer_clear($this->tick_id);
 			}
 			$this->statusChange($elevator_num, "free", $elevator["now"]);
 			return false;
 		}
 		$go = json_decode($elevator['go'], true);
 		if (empty($go)) {
 			echo " go is null\n";
 			// 清除任务, 更改状态
 			if ($this->tick_id){
 				swoole_timer_clear($this->tick_id);
 			}
 			$this->statusChange($elevator_num, "free", $elevator["now"]);
 			return false;
 		}

 		// 本次任务
 		$this_time = $next_time = array();
 		$del= true;
 		// 要去的楼层
 		$want = $next_want = "";
 		// 统计上下任务数量
 		$some_num = $diff_num = 0;

 		foreach ($go as $key => $value) {
 			if ($value['status'] == $elevator['status']) {
 				// 同方向任务数量
 				$some_num ++;
 				// 分上下两种情况
 				if ($elevator['status'] == "up" && $value['floor_now'] >= $elevator['now'])  {
 					// 先分配一个 
 					if (empty($want)) {
 						echo "1\n";
 						$want = $value['floor_now'];
 						$this_time = $value;
 					}
 					// 找到最近的楼层
 					// 外面的
					if (empty($value['floor_want'])) {
						if ($value['floor_now'] < $want) {
							$want = $value['floor_now'];
 							$this_time = $value;
						}
					}
					// 里面的
					else {
						// 当前的任务
						if ($this_time == $value) {
							if ($want > $elevator['now']){
								$want = $value['floor_now'];
								$this_time = $value;
								$del = false;
							} else {
								$want = $value['floor_want'];
								$this_time = $value;
							}
						} else {
							// 其他的任务
							if ($value['floor_now'] < $want) {
								$want = $value['floor_now'];
	 						} else if ($value['floor_want'] < $want) {
								$want = $value['floor_want'];
								$this_time = $value;
	 						}

						}
					}

 				} 
 				if ($elevator['status'] == "down" && $value['floor_now'] <= $elevator['now']) {
 					// 先分配一个 
 					if (empty($want)) {
 						$want = $value['floor_now'];
 						$this_time = $value;
 					}
 					// 找到最近的楼层
					if (empty($value['floor_want'])) {
						if ($value['floor_now'] > $want) {
							$want = $value['floor_now'];
 							$this_time = $value;
						}
					} 
					// else if ($value['floor_want'] > $want) {
					// 	$want = $value['floor_want'];
					// 	$this_time = $value;
					// }
					else {
						if ($value['floor_now'] > $want) {
							$want = $value['floor_now'];
							$this_time = $value;
						} else if ($value['floor_want'] > $want) {
							$want = $value['floor_want'];
							$this_time = $value;

						}
					}
 				} 
 			} else {
 				// 不同方向任务数量
 				$diff_num ++;
 				// 分上下两种情况
 				if ($elevator['status'] == "up" && $value['floor_now'] >= $elevator['now'])  {
 					// 先分配一个 
 					if (empty($next_want)) {
 						$next_want = $value['floor_now'];
 						$next_time = $value;
 					}
 					// 找到最近的楼层

					if ($value['floor_now'] < $next_want) {
						$next_want = $value['floor_now'];
						$next_time = $value;
					}

					// if (empty($value['floor_next_want'])) {
					// 	if ($value['floor_now'] < $next_want) {
					// 		$next_want = $value['floor_now'];
 				// 			$next_time = $value;
					// 	}
					// } else if ($value['floor_next_want'] < $next_want) {
					// 	$next_want = $value['floor_want'];
					// 	$next_time = $value;
					// }

 				} 
 				if ($elevator['status'] == "down" && $value['floor_now'] <= $elevator['now']) {
 					// 先分配一个 
 					if (empty($want)) {
 						$want = $value['floor_now'];
 						$next_time = $value;
 					}
 					// 找到最近的楼层

					if ($value['floor_now'] > $want) {
						$want = $value['floor_now'];
						$next_time = $value;
					}

					// if (empty($value['floor_want'])) {
					// 	if ($value['floor_now'] > $want) {
					// 		$want = $value['floor_now'];
 				// 			$next_time = $value;
					// 	}
					// } else if ($value['floor_want'] > $want) {
					// 	$want = $value['floor_want'];
					// 	$next_time = $value;
					// }
 				} 
 			}

 		}
 		if ($this_time){
	 		// 任务执行
	 		if ($this->working($elevator_num, $elevator['now'], $want)) {
	 			if ($del){
		 			// 从任务队列删除任务
		 			$res = $this->delFloor($elevator_num, $this_time['floor_now'], $this_time['floor_want'], $this_time['status']);
	 			}
	 			// 更改电梯状态
	 			if ($some_num > 1 || !$del) {
	 				$status = $elevator['status'];
	 			} else if ($diff_num > 0) {
					$status = $elevator['status'] == "up" ? "down" : "up";
	 			} else {
	 				$status = "free";
	 			}
	 			$res = $this->statusChange($elevator_num, $status, $want);
	 			//推送
	 			$this->serv->push($fd, "elevator:{$elevator_num} from {$elevator['now']} to {$want}"); 
	 		}
	 	} else {
	 		// echo " this_time is null\n";
	 		// var_dump($elevator,$go);
	 		if ($next_time){
		 		// 任务执行
		 		if ($this->working($elevator_num, $elevator['now'], $next_want)) {
		 			// 从任务队列删除任务
		 			$res = $this->delFloor($elevator_num, $next_time['floor_now'], $next_want, $next_time['status']);
		 			// 更改电梯状态
		 			if ($some_num > 1) {
		 				$status = $elevator['status'];
		 			} else if ($diff_num > 0) {
						$status = $elevator['status'] == "up" ? "down" : "up";
		 			} else {
		 				$status = "free";
		 			}
		 			$res = $this->statusChange($elevator_num, $status, $next_want);
		 			var_dump($res);
		 			//推送
		 			$this->serv->push($fd, "elevator:{$elevator_num} from {$elevator['now']} to {$next_want}"); 
		 		}
	 		}
	 	}
 		return true;
 	}

 	// 模拟执行一个任务
 	public function working($elevator_num, $now, $want) {
 		$msg = "elevator:{$elevator_num} from {$now} to {$want}";
 		// $this->serv->push($elevator_num, json_encode(array("msg" => $msg)));
 		// sleep(5);
 		$msg = "elevator:{$elevator_num} is on {$want}";
 		// $this->serv->push($elevator_num, json_encode(array("msg" => $msg)));
 		return true;
 	}
}


require_once("./config.php");
$sys = new Floor($config);
$sys->run();



