<?php
include("../includes/common.php");

if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

function display_type($type){
	if($type==1)
		return '支付宝';
	elseif($type==2)
		return '微信';
	elseif($type==3)
		return 'QQ钱包';
	elseif($type==4)
		return '银行卡';
	else
		return 1;
}

function display_status($status){
	if($status==1){
		return '已支付';
	}elseif($status==2){
		return '已退款';
	}elseif($status==3){
		return '已冻结';
	}else{
		return '未支付';
	}
}

function text_encoding($text){
	return $text;
}

function csv_escape_cell($value){
	$value = (string)$value;
	$value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
	$trimmed = ltrim($value);
	if($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t"], true)){
		$value = "'".$value;
	}
	if(strpos($value, ',') !== false || strpos($value, '"') !== false){
		$value = '"'.str_replace('"', '""', $value).'"';
	}
	return $value;
}

function csv_safe_date($date, $end = false){
	if($date === '') return '';
	$time = strtotime($date.($end ? ' 23:59:59' : ' 00:00:00'));
	return $time ? date('Y-m-d H:i:s', $time) : '';
}

function csv_add_where(&$where, &$params, $condition, $name, $value){
	$where[] = $condition;
	$params[$name] = $value;
}

switch($act){
case 'settle':
$type = isset($_GET['type'])?trim($_GET['type']):'common';
$batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';
if($batch === '')sysmsg('批次号不能为空');
$batchrow = $DB->getRow("SELECT batch FROM pre_batch WHERE batch=:batch LIMIT 1", [':batch'=>$batch]);
if(!$batchrow)sysmsg('批次号不存在');
$batch = $batchrow['batch'];
$remark = text_encoding($conf['transfer_desc']);

if($type == 'mybank'){
	$data="收款方名称,收款方账号,收款方开户行名称,收款行联行号,金额,附言/用途\r\n";

	$rs=$DB->query("SELECT * from pre_settle where batch=:batch and (type=1 or type=4) order by id asc", [':batch'=>$batch]);
	$i=0;
	while($row = $rs->fetch())
	{
		$i++;
		$data.=csv_escape_cell(text_encoding($row['username'])).','.csv_escape_cell($row['account']).','.($row['type']=='1'?'支付宝':'').',,'.$row['realmoney'].','.csv_escape_cell($remark)."\r\n";
	}

}elseif($type == 'alipay'){
	$data="支付宝批量付款文件模板\r\n";
	$data.="序号（必填）,收款方支付宝账号（必填）,收款方姓名（必填）,金额（必填，单位：元）,备注（选填）\r\n";

	$rs=$DB->query("SELECT * from pre_settle where batch=:batch and type=1 order by id asc", [':batch'=>$batch]);
	$i=0;
	while($row = $rs->fetch())
	{
		$i++;
		$data.=$i.','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).','.$row['realmoney'].','.csv_escape_cell($remark)."\r\n";
	}

}elseif($type == 'wxpay'){
	if(!$conf['transfer_wxpay'])sysmsg("未开启微信企业付款");
	$channel = \lib\Channel::get($conf['transfer_wxpay']);
	if(!$channel)sysmsg("当前支付通道信息不存在");
	$wxinfo = \lib\Channel::getWeixin($channel['appwxmp']);
	if(!$wxinfo)sysmsg("支付通道绑定的微信公众号不存在");

	$rs=$DB->query("SELECT * from pre_settle where batch=:batch and type=2 order by id asc", [':batch'=>$batch]);
	$i=0;
	$table="商家明细单号（必填）,收款用户openid（必填）,收款用户姓名（选填）,收款用户身份证（选填）,转账金额（必填，单位：元）,转账备注（必填）\r\n";
	$allmoney = 0;
	while($row = $rs->fetch())
	{
		$i++;
		$table.=csv_escape_cell($batch.$i).','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).',,'.$row['realmoney'].','.csv_escape_cell($remark)."\r\n";
		$allmoney+=$row['realmoney'];
	}

	$data="微信支付批量转账到零钱模版（勿删）\r\n";
	$data.="商家批次单号（必填）,".$batch."\r\n";
	$data.="批次名称（必填）,批量转账".$batch."\r\n";
	$data.="转账appid（必填）,".$wxinfo['appid']."\r\n";
	$data.="转账总金额（必填，单位：元）,".$allmoney."\r\n";
	$data.="转账总笔数（必填）,".$i."\r\n";
	$data.="批次备注（必填）,批量转账".$batch."\r\n";
	$data.=",\r\n";
	$data.="转账明细（勿删）\r\n";
	$data.=$table;

}else{
	$data="序号,转账方式,收款账号,收款人姓名,转账金额（元）,转账备注\r\n";
	$rs=$DB->query("SELECT * from pre_settle where batch=:batch order by type asc,id asc", [':batch'=>$batch]);
	$i=0;
	while($row = $rs->fetch())
	{
		$i++;
		$data.=$i.','.display_type($row['type']).','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).','.$row['realmoney'].','.csv_escape_cell($remark)."\r\n";
	}

}

if($type == 'mybank' || $type == 'alipay'){
	$data = mb_convert_encoding($data, 'GBK', 'UTF-8');
}else{
	$data = hex2bin('efbbbf').$data;
}
$file_name='pay_'.$type.'_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $batch).'.csv';
$file_size=strlen($data);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $data;
break;

case 'ustat':
$startday = trim($_GET['startday']);
$endday = trim($_GET['endday']);
$method = trim($_GET['method']);
$type = intval($_GET['type']);
if(!$startday || !$endday)exit("<script language='javascript'>alert('param error');history.go(-1);</script>");
$startday_sql = csv_safe_date($startday, false);
$endday_sql = csv_safe_date($endday, true);
if(!$startday_sql || !$endday_sql)exit("<script language='javascript'>alert('date param error');history.go(-1);</script>");
$data = [];
$columns = ['uid'=>'商户ID', 'total'=>'总计'];

if($method == 'type'){
	$paytype = [];
	$rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
	foreach($rs as $row){
		$paytype[$row['id']] = text_encoding($row['showname']);
		if($type == 4){
			$columns['type_'.$row['name']] = text_encoding($row['showname']);
		}else{
			$columns['type_'.$row['id']] = text_encoding($row['showname']);
		}
	}
	unset($rs);
}else{
	$channel = [];
	$rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
	foreach($rs as $row){
		$channel[$row['id']] = text_encoding($row['name']);
	}
	unset($rs);
}

if($type == 4){
	$rs=$DB->query("SELECT uid,type,channel,money from pre_transfer where status=1 and paytime>=:startday and paytime<=:endday", [':startday'=>$startday_sql, ':endday'=>$endday_sql]);
	while($row = $rs->fetch())
	{
		$money = (float)$row['money'];
		if(!array_key_exists($row['uid'], $data)) $data[$row['uid']] = ['uid'=>$row['uid'], 'total'=>0];
		$data[$row['uid']]['total'] += $money;
		if($method == 'type'){
			$ukey = 'type_'.$row['type'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
		}else{
			$ukey = 'channel_'.$row['channel'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
			if(!in_array($ukey, $columns)) $columns[$ukey] = $channel[$row['channel']];
		}
	}
}else{
	$rs=$DB->query("SELECT uid,type,channel,money,realmoney,getmoney,profitmoney from pre_order where status=1 and date>=:startday and date<=:endday", [':startday'=>$startday_sql, ':endday'=>$endday_sql]);
	while($row = $rs->fetch())
	{
		if($type == 3){
			$money = (float)$row['profitmoney'];
		}elseif($type == 2){
			$money = (float)$row['getmoney'];
		}elseif($type == 1){
			$money = (float)$row['realmoney'];
		}else{
			$money = (float)$row['money'];
		}
		if(!array_key_exists($row['uid'], $data)) $data[$row['uid']] = ['uid'=>$row['uid'], 'total'=>0];
		$data[$row['uid']]['total'] += $money;
		if($method == 'type'){
			$ukey = 'type_'.$row['type'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
		}else{
			$ukey = 'channel_'.$row['channel'];
			if(!array_key_exists($ukey, $data[$row['uid']])) $data[$row['uid']][$ukey] = $money;
			else $data[$row['uid']][$ukey] += $money;
			if(!in_array($ukey, $columns)) $columns[$ukey] = $channel[$row['channel']];
		}
	}
}
ksort($data);

$file='';
foreach($columns as $column){
	$file.=$column.',';
}
$file=substr($file,0,-1)."\r\n";
foreach($data as $row){
	foreach($columns as $key=>$column){
		if(!array_key_exists($key, $row))
			$file.='0,';
		else
			$file.=$row[$key].',';
	}
	$file=substr($file,0,-1)."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='pay_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $method).'_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $startday).'_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $endday).'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'order':
$starttime = trim($_GET['starttime']);
$endtime = trim($_GET['endtime']);
$uid = intval($_GET['uid']);
$type = intval($_GET['type']);
$channel = intval($_GET['channel']);
$dstatus = intval($_GET['dstatus']);

$paytype = [];
$rs = $DB->getAll("SELECT * FROM pre_type");
foreach($rs as $row){
	$paytype[$row['id']] = text_encoding($row['showname']);
}
unset($rs);

$where=["1=1"];
$params=[];
if(!empty($uid)) {
	csv_add_where($where, $params, "A.`uid`=:uid", ':uid', $uid);
}
if(!empty($type)) {
	csv_add_where($where, $params, "A.`type`=:type", ':type', $type);
}elseif(!empty($channel)) {
	csv_add_where($where, $params, "A.`channel`=:channel", ':channel', $channel);
}
if($dstatus>-1) {
	csv_add_where($where, $params, "A.status=:status", ':status', $dstatus);
}
if(!empty($starttime)){
	$starttime = csv_safe_date($starttime, false);
	if($starttime) csv_add_where($where, $params, "A.addtime>=:starttime", ':starttime', $starttime);
}
if(!empty($endtime)){
	$endtime = csv_safe_date($endtime, true);
	if($endtime) csv_add_where($where, $params, "A.addtime<:endtime", ':endtime', $endtime);
}
$sql=' '.implode(' AND ', $where);

$file="系统订单号,商户订单号,接口订单号,商户号,网站域名,商品名称,订单金额,实际支付,商户分成,支付方式,支付通道ID,支付插件,支付账号,支付IP,创建时间,完成时间,支付状态,已退款金额,退款时间\r\n";

$rs = $DB->query("SELECT A.*,B.plugin FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE{$sql} order by trade_no desc limit 100000", $params);
while($row = $rs->fetch()){
	if($row['status']==2){
		$row['refundtime'] = $DB->findColumn('refundorder', 'addtime', ['trade_no'=>$row['trade_no']], 'refund_no DESC');
	}
	$file.=csv_escape_cell($row['trade_no']).','.csv_escape_cell($row['out_trade_no']).','.csv_escape_cell($row['api_trade_no']).','.$row['uid'].','.csv_escape_cell($row['domain']).','.csv_escape_cell(text_encoding($row['name'])).','.$row['money'].','.$row['realmoney'].','.$row['getmoney'].','.csv_escape_cell($paytype[$row['type']]).','.$row['channel'].','.csv_escape_cell($row['plugin']).','.csv_escape_cell($row['buyer']).','.csv_escape_cell($row['ip']).','.$row['addtime'].','.$row['endtime'].','.display_status($row['status']).','.($row['status']==2?$row['refundmoney']:'').','.$row['refundtime']."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='order_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $starttime).'_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $endtime).'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'user':
$starttime = trim($_GET['starttime']);
$endtime = trim($_GET['endtime']);
$gid = intval($_GET['gid']);
$dstatus = intval($_GET['dstatus']);

$group = [];
$rs = $DB->getAll("SELECT * FROM pre_group");
foreach($rs as $row){
	$group[$row['gid']] = text_encoding($row['name']);
}
unset($rs);
$status_text = [0=>'封禁', 1=>'正常', 2=>'未审核'];
$permit_text = [0=>'关闭', 1=>'开启'];
$cert_text = [0=>'未认证', 1=>'已认证'];

$where=["1=1"];
$params=[];
if(!empty($gid)) {
	csv_add_where($where, $params, "`gid`=:gid", ':gid', $gid);
}
if(!empty($dstatus)) {
	csv_add_where($where, $params, "`status`=:status", ':status', $dstatus);
}
if(!empty($starttime)){
	$starttime = csv_safe_date($starttime, false);
	if($starttime) csv_add_where($where, $params, "addtime>=:starttime", ':starttime', $starttime);
}
if(!empty($endtime)){
	$endtime = csv_safe_date($endtime, true);
	if($endtime) csv_add_where($where, $params, "addtime<:endtime", ':endtime', $endtime);
}
$sql=' '.implode(' AND ', $where);

$file="用户ID,上级用户ID,用户组,手机号,邮箱,QQ,结算方式,结算账号,结算姓名,余额,保证金,注册时间,上次登录,商户状态,支付权限,结算权限,实名认证,聚合收款码链接\r\n";

$rs = $DB->query("SELECT * FROM pre_user WHERE{$sql} order by uid desc limit 100000", $params);
while($row = $rs->fetch()){
	$code_url = $siteurl.'paypage/?merchant='.authcode($row['uid'], 'ENCODE', SYS_KEY);
	$file.=$row['uid'].','.$row['upid'].','.csv_escape_cell($group[$row['gid']]).','.csv_escape_cell($row['phone']).','.csv_escape_cell($row['email']).','.csv_escape_cell($row['qq']).','.display_type($row['settle_id']).','.csv_escape_cell(text_encoding($row['account'])).','.csv_escape_cell(text_encoding($row['username'])).','.$row['money'].','.$row['deposit'].','.$row['addtime'].','.$row['lasttime'].','.$status_text[$row['status']].','.$permit_text[$row['pay']].','.$permit_text[$row['settle']].','.$cert_text[$row['cert']].','.csv_escape_cell($code_url)."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='user_'.time().'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'record':
$starttime = trim($_GET['starttime']);
$endtime = trim($_GET['endtime']);
$uid = intval($_GET['uid']);
$type = trim($_GET['type']);

$where=["1=1"];
$params=[];
if(!empty($uid)) {
	csv_add_where($where, $params, "`uid`=:uid", ':uid', $uid);
}
if(!empty($type)) {
	csv_add_where($where, $params, "`type`=:type", ':type', $type);
}
if(!empty($starttime)){
	$starttime = csv_safe_date($starttime, false);
	if($starttime) csv_add_where($where, $params, "`date`>=:starttime", ':starttime', $starttime);
}
if(!empty($endtime)){
	$endtime = csv_safe_date($endtime, true);
	if($endtime) csv_add_where($where, $params, "`date`<:endtime", ':endtime', $endtime);
}
$sql=' '.implode(' AND ', $where);

$file="ID,商户号,操作类型,变更类型,变更金额,变更前金额,变更后金额,时间,关联订单号\r\n";

$rs = $DB->query("SELECT * FROM pre_record WHERE{$sql} order by id desc limit 100000", $params);
while($row = $rs->fetch()){
	$file.=$row['id'].','.$row['uid'].','.csv_escape_cell(text_encoding($row['type'])).','.($row['action']==2?'-':'+').','.$row['money'].','.$row['oldmoney'].','.$row['newmoney'].','.$row['date'].','.csv_escape_cell($row['trade_no'])."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='record_'.time().'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'transfer':
$remark = text_encoding($conf['transfer_desc']);
$starttime = trim($_GET['starttime']);
$endtime = trim($_GET['endtime']);
$uid = intval($_GET['uid']);
$dstatus = isNullOrEmpty($_GET['dstatus']) ? '' : intval($_GET['dstatus']);
$type = trim($_GET['type']);
$sheet = trim($_GET['sheet']);
$allowedTransferTypes = ['alipay','wxpay','qqpay','bank'];
if($type !== '' && !in_array($type, $allowedTransferTypes, true)) $type = '';
if($sheet !== '' && !in_array($sheet, ['mybank','alipay','wxpay'], true)) $sheet = '';

$where=["1=1"];
$params=[];
if(!empty($uid)) {
	csv_add_where($where, $params, "`uid`=:uid", ':uid', $uid);
}
if($sheet == 'alipay'){
	$where[] = "`type`='alipay'";
}elseif($sheet == 'wxpay'){
	$where[] = "`type`='wxpay'";
}elseif($sheet == 'mybank'){
	$where[] = "(`type`='alipay' OR `type`='bank')";
}elseif(!empty($type)) {
	csv_add_where($where, $params, "`type`=:type", ':type', $type);
}
if(!isNullOrEmpty($dstatus)) {
	csv_add_where($where, $params, "`status`=:status", ':status', $dstatus);
}
if(!empty($starttime)){
	$starttime = csv_safe_date($starttime, false);
	if($starttime) csv_add_where($where, $params, "`addtime`>=:starttime", ':starttime', $starttime);
}
if(!empty($endtime)){
	$endtime = csv_safe_date($endtime, true);
	if($endtime) csv_add_where($where, $params, "`addtime`<:endtime", ':endtime', $endtime);
}
$sql=' '.implode(' AND ', $where);
$rs = $DB->query("SELECT * FROM pre_transfer WHERE{$sql} order by biz_no desc limit 100000", $params);

if($sheet == 'mybank'){
	$data="收款方名称,收款方账号,收款方开户行名称,收款行联行号,金额,附言/用途\r\n";

	$i=0;
	while($row = $rs->fetch())
	{
		$i++;
		$desc = $row['desc'] ? text_encoding($row['desc']) : $remark;
		$data.=csv_escape_cell(text_encoding($row['username'])).','.csv_escape_cell($row['account']).','.($row['type']=='1'?'支付宝':'').',,'.$row['money'].','.csv_escape_cell($desc)."\r\n";
	}

}elseif($sheet == 'alipay'){
	$data="支付宝批量付款文件模板\r\n";
	$data.="序号（必填）,收款方支付宝账号（必填）,收款方姓名（必填）,金额（必填，单位：元）,备注（选填）\r\n";

	$i=0;
	while($row = $rs->fetch())
	{
		$i++;
		$desc = $row['desc'] ? text_encoding($row['desc']) : $remark;
		$data.=$i.','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).','.$row['money'].','.csv_escape_cell($desc)."\r\n";
	}

}elseif($sheet == 'wxpay'){
	if(!$conf['transfer_wxpay'])sysmsg("未开启微信企业付款");
	$channel = \lib\Channel::get($conf['transfer_wxpay']);
	if(!$channel)sysmsg("当前支付通道信息不存在");
	$wxinfo = \lib\Channel::getWeixin($channel['appwxmp']);
	if(!$wxinfo)sysmsg("支付通道绑定的微信公众号不存在");

	$i=0;
	$table="商家明细单号（必填）,收款用户openid（必填）,收款用户姓名（选填）,收款用户身份证（选填）,转账金额（必填，单位：元）,转账备注（必填）\r\n";
	$allmoney = 0;
	while($row = $rs->fetch())
	{
		$i++;
		$desc = $row['desc'] ? text_encoding($row['desc']) : $remark;
		$table.=csv_escape_cell($batch.$i).','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).',,'.$row['money'].','.csv_escape_cell($desc)."\r\n";
		$allmoney+=$row['money'];
	}

	$data="微信支付批量转账到零钱模版（勿删）\r\n";
	$data.="商家批次单号（必填）,".$batch."\r\n";
	$data.="批次名称（必填）,批量转账".$batch."\r\n";
	$data.="转账appid（必填）,".$wxinfo['appid']."\r\n";
	$data.="转账总金额（必填，单位：元）,".$allmoney."\r\n";
	$data.="转账总笔数（必填）,".$i."\r\n";
	$data.="批次备注（必填）,批量转账".$batch."\r\n";
	$data.=",\r\n";
	$data.="转账明细（勿删）\r\n";
	$data.=$table;

}else{
	$type_name = ['alipay'=>'支付宝', 'wxpay'=>'微信', 'qqpay'=>'QQ钱包', 'bank'=>'银行卡'];
	if(!empty($type)){
		$data="序号,收款账号,收款人姓名,转账金额（元）,转账备注\r\n";
		$i=0;
		while($row = $rs->fetch())
		{
			$i++;
			$desc = $row['desc'] ? text_encoding($row['desc']) : $remark;
			$data.=$i.','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).','.$row['money'].','.csv_escape_cell($desc)."\r\n";
		}
	}else{
		$data="序号,转账方式,收款账号,收款人姓名,转账金额（元）,转账时间,转账备注,状态,失败原因\r\n";
		$status_arr = ['0'=>'正在处理','1'=>'转账成功','2'=>'转账失败','3'=>'待处理'];
		$i=0;
		while($row = $rs->fetch())
		{
			$i++;
			$desc = $row['desc'] ? text_encoding($row['desc']) : $remark;
			$data.=$i.','.$type_name[$row['type']].','.csv_escape_cell($row['account']).','.csv_escape_cell(text_encoding($row['username'])).','.$row['money'].','.$row['addtime'].','.csv_escape_cell($desc).','.$status_arr[$row['status']].','.csv_escape_cell($row['result'])."\r\n";
		}
	}
}

if($sheet == 'mybank' || $sheet == 'alipay'){
	$data = mb_convert_encoding($data, 'GBK', 'UTF-8');
}else{
	$data = hex2bin('efbbbf').$data;
}
$file_name='transfer_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $sheet).'_'.time().'.csv';
$file_size=strlen($data);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $data;
break;

case 'complain':
$paytype = [];
$rs = $DB->getAll("SELECT * FROM pre_type");
foreach($rs as $row){
	$paytype[$row['id']] = $row['showname'];
}
unset($rs);

$where=["1=1"];
$params=[];
if(isset($_GET['uid']) && !empty($_GET['uid'])) {
	$uid = intval($_GET['uid']);
	csv_add_where($where, $params, "A.`uid`=:uid", ':uid', $uid);
}
if(isset($_GET['paytype']) && !empty($_GET['paytype'])) {
	$paytypen = intval($_GET['paytype']);
	csv_add_where($where, $params, "A.`paytype`=:paytype", ':paytype', $paytypen);
}elseif(isset($_GET['channel']) && !empty($_GET['channel'])) {
	$channel = intval($_GET['channel']);
	csv_add_where($where, $params, "A.`channel`=:channel", ':channel', $channel);
}
if(isset($_GET['dstatus']) && $_GET['dstatus']>-1) {
	$dstatus = intval($_GET['dstatus']);
	csv_add_where($where, $params, "A.`status`=:status", ':status', $dstatus);
}
if(!empty($_GET['starttime']) || !empty($_GET['endtime'])){
	if(!empty($_GET['starttime'])){
		$starttime = csv_safe_date(trim($_GET['starttime']), false);
		if($starttime) csv_add_where($where, $params, "A.addtime>=:starttime", ':starttime', $starttime);
	}
	if(!empty($_GET['endtime'])){
		$endtime = csv_safe_date(trim($_GET['endtime']), true);
		if($endtime) csv_add_where($where, $params, "A.addtime<=:endtime", ':endtime', $endtime);
	}
}
if(isset($_GET['value']) && !empty($_GET['value'])) {
	$column = isset($_GET['column']) ? trim($_GET['column']) : '';
	$value = trim($_GET['value']);
	$allowedColumns = ['id','uid','paytype','channel','trade_no','title','content','status','type'];
	if(in_array($column, $allowedColumns, true)){
		if($column=='title' || $column=='content'){
			csv_add_where($where, $params, "A.`{$column}` like :search_value", ':search_value', '%'.$value.'%');
		}else{
			csv_add_where($where, $params, "A.`{$column}`=:search_value", ':search_value', $value);
		}
	}
}
$sql=' '.implode(' AND ', $where);

$file="ID,商户号,支付方式,通道ID,关联订单号,商品名称,订单金额,问题类型,投诉原因,投诉详情,创建时间,最后更新时间,状态\r\n";

$rs = $DB->query("SELECT A.*,B.money,B.name ordername,B.status orderstatus FROM pre_complain A LEFT JOIN pre_order B ON A.trade_no=B.trade_no WHERE{$sql} order by A.addtime desc limit 100000", $params);
while($row = $rs->fetch()){
	$file.=''.$row['id'].','.$row['uid'].','.csv_escape_cell($paytype[$row['paytype']]).','.$row['channel'].','.csv_escape_cell($row['trade_no']).','.csv_escape_cell($row['ordername']).','.$row['money'].','.$row['type'].','.csv_escape_cell($row['title']).','.csv_escape_cell($row['content']).','.$row['addtime'].','.$row['edittime'].','.['0'=>'待处理','1'=>'处理中','2'=>'处理完成'][$row['status']]."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='complain_'.time().'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'wximg':
	requireAdminCsrf(false);
	$channelid = isset($_GET['channel']) ? intval($_GET['channel']) : 0;
	$subchannelid = isset($_GET['subchannel']) ? intval($_GET['subchannel']) : 0;
	$media_id = isset($_GET['mediaid']) ? trim($_GET['mediaid']) : '';
	if($channelid <= 0 && $subchannelid <= 0) exit('Invalid channel');
	if($media_id === '' || strlen($media_id) > 128 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $media_id)) exit('Invalid media id');
	$channel = $subchannelid ? \lib\Channel::getSub($subchannelid) : \lib\Channel::get($channelid);
	if(!$channel) exit('Invalid channel');
	$model = \lib\Complain\CommUtil::getModel($channel);
	$image = $model->getImage($media_id);
	if($image !== false){
		$seconds_to_cache = 3600*24*7;
		header("Cache-Control: max-age=$seconds_to_cache");
		header("Content-Type: image/jpeg");
		echo $image;
	}
break;

case 'proxyapi':
	requireAdminCsrf(false);
	$apikey = isset($_GET['apikey']) ? trim($_GET['apikey']) : '';
	if(!hash_equals((string)$conf['proxy_apikey'], (string)$apikey)) exit('Invalid apikey');
	if(empty($apikey)) exit("<script language='javascript'>alert('请先保存API接口密钥');history.go(-1);</script>");
	$content = file_get_contents('proxy_api.tpl');
	$content = str_replace('{apikey}', $apikey, $content);
	$file_name='index.php';
	$file_size=strlen($content);
	header("Content-Description: File Transfer");
	header("Content-Type: application/force-download");
	header("Content-Length: {$file_size}");
	header("Content-Disposition:attachment; filename={$file_name}");
	echo $content;
break;
default:
	exit('No Act');
break;
}