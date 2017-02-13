# interest

要求
----
* 已安装swoole

运行
----
* server端是floor_websocket.php,运行方式: php floor_websocket.php
* client端是interest/floor.html,运行方式:在浏览器中打开即可

floor.html参数输入说明
----
* elevator_num: 电梯编号
* now: 当前所在乘数
* status: up | down 上升或下降, 模拟电梯外部按钮
* want: 想去的楼层数, 模拟电梯内部按钮,可为空