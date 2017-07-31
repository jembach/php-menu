<?php

/**
 * Class for database connection
 * @category  Menu organisation
 * @package   php-menu
 * @author    Jonas Embach
 * @copyright Copyright (c) 2017
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      https://github.com/jembach/php-menu
 * @version   1.0-master
 */

class menu {

	protected static $db;				// Holds the database connection
	protected static $menu=array();		// Holds all menu entry's in cache that where alreadey selected
	protected static $groupIDs=array();	// Holds all menu Groups that where already selected
	protected static $metaTags=array(); // Holds the meta information that are additional to save                              
	
	/**
	 * initialize the class to access functions static
	 * also it creates the database table
	 */
	public function checkObject() {
		if (self::$db===null) {
			if(defined("DB_USER") && defined("DB_PASSWORD") && defined("DB_HOST") && defined("DB_DATABASE"))
				self::$db=new db(DB_HOST,DB_USER,DB_PASSWORD,DB_DATABASE);
			else {
				throw new Exception("To use this extension you have to set the databse connection information!", 1);
				return;
			}
			if(self::$db->tableExists('config')==false){
				self::$db->startTransaction();
				self::$db->rawSQL("CREATE TABLE `menu` (`ID` int(11) NOT NULL,`name` varchar(255) NOT NULL,
														`href` varchar(255) NOT NULL DEFAULT '',`priority` int(11) NOT NULL,
														`subgroup` int(11) NOT NULL,`meta` text NOT NULL,
														`active` smallint(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
				self::$db->rawSQL("ALTER TABLE `menu` ADD PRIMARY KEY (`ID`);");
				self::$db->rawSQL("ALTER TABLE `menu` MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;");
				self::$db->commitTransaction();
			}
		}
	}

	
	/**
	 * returns all menu entry's that are in the same group
	 * @param      integer   $id     The entry identifier of one entry in the group
	 * @return     array 		  	 The menu entry's
	 */
	public function getEntrysByMenuID($id){
		if(self::checkMenu($id)){
			return self::getEntrysInGroup(self::convertIDToSubgroup($id));
		} else {
			return array();
		}
	}
	
	/**
	 * returns all menu entry's that are in a group
	 * @param      integer         $group  	The group identifier
	 * @return     array 		  			The menu entrys in the group
	 */
	function getEntrysInGroup($group){
		#prüfen, ob Gruppe schonmal aufgerufen wurde
		switch(in_array($group,self::$groupIDs)){
			case 0:
				$result=self::$db->Select('menu',new dbCond('subgroup',$group),new dbOrder('priority','ASC'));
				self::$groupIDs[]=$group;
				if($result==null){
					return array();
				} else {
					#ergebnisse durchgehen
					foreach($result as $data){
						self::saveData($data);
					}
					#nochmal durchgehen -> sortieren
					return self::getEntrysInGroup($group);
				}
			  break;
			case 1:
				$returnValue=array();
				#alle bereits zwischengespeicherten elemente durchgehen
				foreach(self::$menu as $element){
					if(isset($element['subgroup']) && $element['subgroup']==$group){ $returnValue[]=$element; }
				}
				#array sortieren
				usort($returnValue, function($a, $b) { return $a['priority'] - $b['priority']; });
				return $returnValue;
			break;
		}
	}
	
	/**
	 * returns the subgroup ID by a member ID
	 * @param      integer   $id   	The identifier of the menu entry
	 * @return     boolean|integer  The subgroup ID
	 */
	function convertIDToSubgroup($id){
		if(isset(self::$menu[$id])) return self::$menu[$id]['subgroup'];
		else {
			$result=self::$db->Select('menu',new dbCond('ID',$id));
			if($result==null){
				return false;
			} else {
				self::saveData($result[0]);
				return $result[0]['subgroup'];
			}
		}
	}
	
	/**
	 * returns all information about a menu entry
	 * @param      integer  $id   The identifier of the menu entry
	 * @return     array 		  The menu entry information
	 */
	function getEntry($id){
		if(isset(self::$menu[$id])) 
			return self::$menu[$id];
		else {
			$result=self::$db->Select('menu',new dbCond('ID', $id));
			if($result==null)
				return array();
			else { 
				self::saveData($result[0]);
				return self::$menu[$result[0]['ID']];
			}
		}
	}
	
	/**
	 * proove if an entry is registered in the databse
	 * @param      integer   $id 	The identifier
	 * @return     boolean  		true if exists, false if not 
	 */
	function checkEntry($id){
		if(isset(self::$menu[$id])){ return true; }
		else {
			$result=self::$db->Select('menu__',new dbCond('ID',$id));
			self::saveData($result[0]);
			if(($result!=null)&&(count($result)==1)) return true;
		}
	}
	
	/**
	 * returns the title of the entry
	 * @param      integer  $id 	The identifier
	 * @return     string			The title
	 */
	function getDisplayName($id){
		$result=self::$db->Select('menu__',new dbCond('ID',$id));
		if(($result!=null)&&(count($result)==1)) {
			$menu[$result[0]['ID']]=$result[0];
			return $result[0]['name'];
		}
	}
	
	/**
	 * caches an entry
	 * @param      array  $data   The data of an entry
	 */
	protected function saveData($data){
		$data['meta']=unserialize($data['meta']);
		self::$menu[$data['ID']]=$data;
	}
	
	
	/**
	 * updates a menu entry
	 * @param      <type>   $data   The data
	 * @return     boolean  ( description_of_the_return_value )
	 */
	public function update($data){
		if(!isset($data['ID'])) return false;
		$id=$data['ID']; //cache id because in query isn't allowed to override
		//override data
		$data=array_merge(self::getEntry($data['ID']),$data);
		foreach ($data as $key => $value) {
			if(!in_array($key, array("name","subgroup","priority","active","meta","href")))
				unset($data[$key]);
		}
		$data['meta']=serialize($data['meta']);
		self::$db->Update('menu',$data,new dbCond('ID',$id));
		$data['ID']=$id;
		self::saveData($data);
	}

	/**
	 * adds an menu entry
	 * @param      <type>   $data   The data
	 * @return     boolean  		true if insert was succesfully
	 */
	public function add($data){
		$required=array("name","subgroup");
		if(count(array_intersect_key(array_flip($required), $data)) === count($required)){
			if(!isset($data['active'])) $data['active']=1;
			if(!isset($data['priority'])){
				$last=end(self::getEntrysInGroup($data['subgroup']));
				$data['priority']=$last['priority']+1;
				unset(self::$groupIDs[array_search($data['subgroup'],self::$groupIDs)]);
			}
			if(!isset($data['meta']))
				$data['meta']=array();
			$data['meta']=serialize($data['meta']);
			self::$db->Insert('menu',$data);
			return true;
		} else 
			return false;
	}

}
menu::checkObject();
?>
