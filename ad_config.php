<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

//������� URL �����
$base_url='forum.omsk.com';

//��������� ���� ������
$dbhost = '127.0.0.1:3306'; $dbuser='antiddos'; $dbpass='*********'; $dbname=$dbuser;
$dbtable='ip_banned';

//������ ���������� � ��� ����������� ������ ��� ���
$frontends_all=array();
front_add('78.24.217.10' ,'firstvds','8181');
....
front_add('83.69.226.254','hostline','8787',true);//true ���� �� ���������

//������ ���� ������ �������, ��������������� �� ����
function front_add($ip,$front,$port,$recalc=false){
	global $frontends_all;
	$frontends_all[$ip]=array($front,$port);
	if ($recalc) fronts_recalc();
}
function front_get_port($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$row=$frontends_all[$front_ip[$front]];
		return $row[1]; //its a port
	}
}
function fronts_recalc() {
	global $frontends_all;
	global $frontend,$front_ip,$fronts,$fronts_arr;
	$frontend=array();//������ $frontend[$ip]='��� ���������'
	$front_ip=array();//������ $front_ip[��� ���������]=$ip
	$fronts='';//������ ���������� ����� �������: firstvds,truevds,vdscom,inferno
	$fronts_arr=array();//������ ���������� � ���� ������� [0]=>firstvds,[1]=>...
	foreach($frontends_all as $ip=>$row) {
		$front=$row[0];
		//$froun_uniq_port=$row[1];

		$frontend[$ip]=$front;
		
		$front_ip[$front]=$ip;

		if ($fronts) $fronts.=',';
		$fronts.=$front;

		$fronts_arr[]=$front;
	}
}
/**
 * ������������� ���������� � ����� ������ �� ��������� ��������� ���������.
 * ���� �������� $waiting ����� true, �� ������������� �� ��� ��� ���� �� ����������.
 * ���� $waiting=false, ����� ��� ��������� ������� ���������� ������� � ��������� false
 * ���� $only_check_link=true �� ������ ���������� ������� ��������� $dblink
 */
function db_connect($waiting=false,$only_check_link=false) {
	global $dbhost, $dbuser,$dbpass, $dbname;
	static $dblink=false;
	if ($only_check_link) return $dblink;
	//���� ���������� � ����� �������� ��� �������������, �� ������� ���.
	if ($dblink) { @mysql_close($dblink); $dblink=false; }
	//���� "���� ��������� �� �����������"
	while (!$dblink) {
		echo "Connecting to database: $dbhost ...";
		//�������� ������������ � ���� ������
		$dblink = @mysql_connect($dbhost, $dbuser, $dbpass);
		if ($dblink === false) {
			$dberrtxt="Could not connect : " . mysql_error();
		} else {
			//���� ����������� ������� - �������� ������� ���� ������
			if (mysql_select_db($dbname)) break;//���� ������� - �������
			else {//���� ������� ���� ������ �� �������, �������� ���� ������
				$dberrtxt="Could not select database '$dbname'\n";
				@mysql_close($dblink); $dblink=false;
			}
		}
		echo $dberrtxt."\n";
		if (!$waiting) break;//���� �������� �� ������ - �������
		sleep(3);//���� 3 ������� ����� ��������� �������� ����������
	}
	if ($dblink !== false) echo "DB $dbname connected.\n";
	return $dblink;
}
/**
 * ������� db_query($sql) - ������ ��� mysql_query($sql)
 * � ������� �� mysql_query �������� ������������ ���������� � ����� ������,
 * �������� ��������������� ���������� � �����������, ���� ��� ���������.
 */
function db_query($sql) {
	//�������� ������� ���������� � ����� ������
	if (db_connect(false,true)===false) { //���� ���������� ���,
		$dblink=db_connect(true); //���������� ���������� ����������
		if (!$dblink) return false; //���� ����������� �� ������� - ������� � false
	}
	//���� ���������� � ����� ������ �������� ������������, ������� ������� ������
	while (false===($result=@mysql_query($sql))) {
		//���� �� �������� ���� ������ �� ������ (������ false)
		echo "err: ".mysql_error()."\n"; //������� ��������� �� ������
		//���� ������ >2000 (�� ���� �������� � ��������) �� �������������,
		//���� ������ ������ - ������� � ���������� false
		if (mysql_errno()>2000) db_connect(true); else break;
	}
	return $result;
}