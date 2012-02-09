<?php


ini_set("display_errors", "1");
error_reporting(E_ALL ^ E_NOTICE);


include_once("config.php");
include_once(INSTALL_PATH . "/Settings.class.php");
include_once(INSTALL_PATH . "/Smarty/Smarty.class.php");


$main = new classMain();
$main->proc();
exit();


class classMain {
	
	
	private $_settings = null;
	private $_db = null;
	private $_smarty = null;
	
	
	public function __construct() {
		$this->_settings = Settings::factory();
		$this->_db = new classDb($this->_settings);
		$this->_smarty = new Smarty();
	}
	
	
	public function proc() {
		try{
			$tag_id = 1;
			$tags = array();
			$records = array();
			$new_records = array();
			
			// チャンネル一覧
			$stations = $this->_getChannelTbl();
			
			// カテゴリ一覧
			$cats = $this->_getCategoryTbl();
			
			// 検索ワード
			$this->_smarty->assign("search", $_POST['search']);
			
			// 録画済レコード
			$rec = $this->_getArchive();
			foreach ($rec as $row) {
				$tmp = array(
					"id"				=> $row["id"], 
					"station_id"		=> $row["channel_id"], 
					"station_name"		=> $stations[$row["channel_id"]], 
					"category_id"		=> $row["category_id"], 
					"category_name"		=> $cats[$row["category_id"]], 
					"starttime"			=> $row["starttime"], 
					"endtime"			=> $row["endtime"], 
					"path"				=> $row["path"], 
					"asf"				=> $this->_settings->install_url . "/viewer.php?reserve_id=" . $row["id"], 
					"title"				=> htmlspecialchars($row["title"], ENT_QUOTES), 
					"description"		=> htmlspecialchars($row["description"], ENT_QUOTES), 
					"thumb"				=> "<img class=\"img_rec\" src=\"" . $this->_settings->install_url . $this->_settings->thumbs . "/" . htmlentities($row["path"], ENT_QUOTES,"UTF-8") . ".jpg\" />", 
					"tag_id"			=> "0", 
					"tag_name"			=> "", 
				);
				
				$tag_name = $this->_getTag($row["path"]);
				if ($tag_name !== "") {
					$find = false;
					foreach ($tags as $tag) {
						if ($tag_name == $tag["name"]) {
							$tmp["tag_id"]		= $tag["id"];
							$tmp["tag_name"]	= $tag["name"];
							$find = true;
							break;
						}
					}
					if (!$find) {
						$tmp["tag_id"]		= $tag_id;
						$tmp["tag_name"]	= $tag_name;
						array_push($tags, array(
							"id"		=> $tmp["tag_id"], 
							"name"		=> $tmp["tag_name"], 
						));
						$tag_id++;
						array_push($new_records, $tmp);
					}
				}
				array_push($records, $tmp);
			}
			
			$this->_smarty->assign("new_records", $new_records);
			$this->_smarty->assign("records", $records);
			$this->_smarty->assign("tags", $tags);
			$this->_smarty->assign("use_thumbs", $this->_settings->use_thumbs);
			
			// 容量
			$disk_free_space = disk_free_space(INSTALL_PATH);
			$disk_total_space = disk_total_space(INSTALL_PATH);
			$disk_use_space = $disk_total_space - $disk_free_space;
			$this->_smarty->assign("disk_use_space", number_format(floor($disk_use_space / 1000 / 1000 / 1000)));
			$this->_smarty->assign("disk_total_space", number_format(floor($disk_total_space / 1000 / 1000 / 1000)));
			$this->_smarty->assign("disk_per", floor($disk_use_space / $disk_total_space * 100));
			
			// 出力
			$this->_smarty->display("recordedTable2.html");
		} catch( exception $e ) {
			exit($e->getMessage());
		}
	}
	
	
	// チャンネル一覧取得
	private function _getChannelTbl() {
		$stations					= array();
		$stations_name				= array();
		$stations[0]["id"]			= 0;
		$stations[0]["name"]		= "すべて";
		$stations[0]["selected"]	= (! $_POST['station']) ? "selected" : "";
		
		$rows = $this->_db->query("SELECT * FROM epgrec.Recorder_channelTbl ");
		foreach($rows as $row) {
			array_push($stations, array(
				"id"		=> $row["id"], 
				"name"		=> $row["name"], 
				"selected"	=> ($row["id"] == $_POST['station']) ? "selected" : "", 
			));
			$stations_name[$row["id"]] = $row["name"];
		}
		$this->_smarty->assign("stations", $stations);
		
		return $stations_name;
	}
	
	
	// カテゴリ一覧取得
	private function _getCategoryTbl() {
		$cats					= array();
		$cats_name				= array();
		$cats[0]["id"]			= 0;
		$cats[0]["name"]		= "すべて";
		$cats[0]["selected"]	= ($_POST['category_id'] == 0) ? "selected" : "";
		
		$rows = $this->_db->query("SELECT * FROM epgrec.Recorder_categoryTbl ");
		foreach($rows as $row) {
			array_push($cats, array(
				"id"		=> $row["id"], 
				"name"		=> $row["name_jp"], 
				"selected"	=> ($row["id"] == $_POST['category_id']) ? "selected" : "", 
			));
			$cats_name[$row["id"]] = $row["name_jp"];
		}
		$this->_smarty->assign("cats", $cats);
		
		return $cats_name;
	}
	
	
	// 予約中番組取得
	private function _getReserve() {
		// SQL
		$sql = "SELECT * FROM epgrec.Recorder_reserveTbl ";
		
		// 検索条件
		$sql .= "WHERE starttime > '" . date("Y-m-d H:i:s") . "'";
		$sql .= " ORDER BY starttime ";
		
		return $this->_db->query($sql);
	}
	
	
	// 録画中番組取得
	private function _getNowRec() {
		// SQL
		$sql = "SELECT * FROM epgrec.Recorder_reserveTbl ";
		
		// 検索条件
		$sql .= "WHERE starttime <= '" . date("Y-m-d H:i:s") . "'";
		$sql .= " endtime >= '" . date("Y-m-d H:i:s") . "'";
		$sql .= " ORDER BY starttime DESC ";
		
		return $this->_db->query($sql);
	}
	
	
	// 録画済番組取得
	private function _getArchive() {
		// SQL
		$sql = "SELECT * FROM epgrec.Recorder_reserveTbl ";
		
		// 検索条件
		$sql .= "WHERE endtime < '" . date("Y-m-d H:i:s") . "'";
		if ($_POST['search'] != "") {
			 $sql .= " AND CONCAT(title,description) like '%" . mysql_real_escape_string($_POST['search']) . "%'";
		}
		if ($_POST['category_id'] != 0 ) {
			$sql .= " AND category_id = '" . $_POST['category_id'] . "'";
		}
		if ($_POST['station'] != 0) {
			$sql .= " AND channel_id = '" . $_POST['station'] . "'";
		}
		$sql .= " ORDER BY starttime DESC ";
		
		return $this->_db->query($sql);
	}
	
	
	// 共通タイトル取得
	private function _getTag($path) {
		$title = "";
		
		$path = str_replace("【新】", "", $path);
		$path = str_replace("【再】", "", $path);
		$path = str_replace("【終】", "", $path);
		$path = str_replace("【字】", "", $path);
		$path = str_replace("【多】", "", $path);
		$path = str_replace("【デ】", "", $path);
		$path = str_replace("！", "", $path);
		$path = str_replace("!", "", $path);
		$path = str_replace("？", "", $path);
		$path = str_replace("?", "", $path);
		$path = str_replace("　", "", $path);
		$path = str_replace(" ", "", $path);
		$path = str_replace(".ts", "", $path);
		$tmp = explode("_", $path);
		$title = $tmp[1];
		
		$pos = strpos($title, "「");
		if ($pos !== false) {
			$title = substr($title, 0, $pos);
		}
		$pos = strpos($title, "（");
		if ($pos !== false) {
			$title = substr($title, 0, $pos);
		}
		$pos = strpos($title, "＃");
		if ($pos !== false) {
			$title = substr($title, 0, $pos);
		}
		$pos = strpos($title, "♯");
		if ($pos !== false) {
			$title = substr($title, 0, $pos);
		}
		$pos = strpos($title, "第");
		if ($pos !== false) {
			$title = substr($title, 0, $pos);
		}
		
		return $title;
	}
	
	
}


class classDb {
	
	
	private $_db_handle = null;
	
	
	public function __construct($settings) {
		$this->_db_handle = mysql_connect($settings->db_host, $settings->db_user, $settings->db_pass);
	}
	
	
	public function __destruct() {
		mysql_close($this->_db_handle);
	}
	
	
	public function query($sql, $param = null) {
		$rows = array();
		
		if (isset($param)) {
			$sql = vsprintf($sql, $param);
		}
		$result = mysql_query($sql);
		while ($row = mysql_fetch_assoc($result)) {
			array_push($rows, $row);
		}
		mysql_free_result($result);
		
		return $rows;
	}
	
	
	public function affectedNum() {
		return mysql_affected_rows($this->_db_handle);
	}
	
	
	public function insertId() {
		return mysql_insert_id();
	}
	
	
}

?>
