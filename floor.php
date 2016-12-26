<?php
class Elevator_System {
	// 电梯
	public static $elevator;
	// 楼层
	public static $floor;
	// 电梯升降状态
	public $elevator_status = ['up' ,'down' ,'free'];
	// 默认电梯参数
	public $elevator_default = ['status'=>'free','floor'=>1];
	// 每楼层按钮
	public $button_action = ['up'=>1,'down'=>1];
	// 全部调度信息
	public static $control = ['up','down'];
	// 使用者信息
	public $user = ['floor','button_action'];

	// 初始化
	public function __construct($num,$floor){
		$this->floor = $floor;
		for($i=1; $i<=$num; $i++){
			$this->elevator[$i] = $elevator_default;
		}
	}

	// 电梯运行
	public function run($elevator_arr){
		if($elevator_arr){
			foreach ($elevator_arr as $key => $value) {
				$this->elevator[$i]['go'];
			}
		}
	}
	// 电梯停止
	public function stop(){

	}
	/**
	*	楼层按钮点击事件
	*	user = ['floor'=>1, 'button_action'=>'up'];
	*/
	public function button_onclick($user){
		// 检查用户输入的动作是否与电梯状态相同
		$elevator_status = get_elevator_status();
		if ( !empty($elevator_status['free']) ){
			// 有空闲的
			$this->control($user, $elevator_status['free']);
		} elseif (!empty($elevator_status[$user['button_action']])) {
			// 有相同
			$this->control($user, $elevator_status[$user['button_action']]);
		} else {
			$this->control($user);
		}
	}
	// 检查当前电梯状态与目标是否一致
	public function get_elevator_status(){
		$result = [];
		foreach ($this->elevator as $key => $value) {
			$result[$value['status']] = $key;
		}
		return $result;
	}

	// 调度信息
	public function control($user, $elevator_arr){

	}


}



$floor = [-2,-1,1,2,3,4,5,6,7,8];