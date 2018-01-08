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
	public static function checkObject() {
		if (self::$db===null) {
			if (self::$db===null) {
				if(defined("DB_USER") && defined("DB_PASSWORD") && defined("DB_HOST") && defined("DB_DATABASE"))
					try {
						self::$db=new db(DB_HOST,DB_USER,DB_PASSWORD,DB_DATABASE);	
					} catch (Exception $e) {
						trigger_error("While accessing the database an error occured: ".$e->getMessage(),E_USER_WARNING);
					}
				else {
					throw new Exception("To use this extension you have to set the databse connection information!", 1);
					return;
				}
			}
			try {
				if(self::$db->tableExists('config')==false){
					self::$db->startTransaction();
					self::$db->rawSQL("CREATE TABLE `menu` (`ID` int(11) NOT NULL,`name` varchar(255) NOT NULL,
															`href` varchar(255) NOT NULL DEFAULT '',`priority` int(11) NOT NULL,
															`subgroup` int(11) NULL,`meta` text NOT NULL,
															`active` smallint(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
					self::$db->rawSQL("ALTER TABLE `menu` ADD PRIMARY KEY (`ID`), ADD KEY `subgroup` (`subgroup`);");
					self::$db->rawSQL("ALTER TABLE `menu` MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;");
					self::$db->rawSQL("ALTER TABLE `menu` ADD CONSTRAINT `menu_ibfk_1` FOREIGN KEY (`subgroup`) REFERENCES `menu` (`ID`) ON DELETE CASCADE ON UPDATE CASCADE;");
					self::$db->rawSQL("INSERT INTO `menu` (`ID`, `name`, `href`, `priority`, `subgroup`, `meta`, `active`) VALUES (0, '', '', 0, NULL, '', 1);");
					self::$db->commitTransaction();
				}
			} catch (Exception $e){
				trigger_error("While loading the database configurations an error occured: ".$e->getMessage(),E_USER_WARNING);
			}
		}
	}

	/**
	 * Gets all entrys.
	 *
	 * @param      integer  $startGroup  The start group
	 *
	 * @return     <type>   All entrys.
	 */
	public static function getAllEntrys($startGroup=0){
		foreach (self::getEntrysInGroup($startGroup) as $subEntry) {
			self::getEntrysInGroup($subEntry['ID'])	;
		}
		return self::$menu;
	}

	/**
	 * returns all menu entry's that are in the same group
	 * @param      integer   $id     The entry identifier of one entry in the group
	 * @return     array 		  	 The menu entry's
	 */
	public static function getEntrysByMenuID($id){
		if(self::checkEntry($id)){
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
	public static function getEntrysInGroup($group){
		#prüfen, ob Gruppe schonmal aufgerufen wurde
		switch(in_array($group,self::$groupIDs)){
			case 0:
				try {
					$result=self::$db->Select('menu',new dbCond('subgroup',$group),new dbOrder('priority','ASC'));
				}
				catch (Exception $e){
					return array();
				}
				self::$groupIDs[]=$group;
				if($result==null || !is_array($result)){
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
	public static function convertIDToSubgroup($id){
		if(isset(self::$menu[$id])) return self::$menu[$id]['subgroup'];
		else {
			try{
				$result=self::$db->Select('menu',new dbCond('ID',$id));
			}
			catch(Exception $e){
				return false;
			}
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
	public static function getEntry($id){
		if(isset(self::$menu[$id])) 
			return self::$menu[$id];
		else {
			try {
				$result=self::$db->Select('menu',new dbCond('ID', $id));
			}
			catch(Exception $e) {
				return array();
			}
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
	public static function checkEntry($id){
		if(isset(self::$menu[$id])){ return true; }
		else {
			try{
				$result=self::$db->Select('menu__',new dbCond('ID',$id));
				self::saveData($result[0]);
				if(($result!=null)&&(count($result)==1)) return true;
			}
			catch(Exception $e) {
				return false;
			}
		}
		return false;
	}
	
	/**
	 * returns the title of the entry
	 * @param      integer  $id 	The identifier
	 * @return     string			The title
	 */
	public static function getDisplayName($id){
		try {
			$result=self::$db->Select('menu__',new dbCond('ID',$id));
		}
		catch(Exception $e) {
			return "";
		}
		if(($result!=null)&&(count($result)==1)) {
			$menu[$result[0]['ID']]=$result[0];
			return $result[0]['name'];
		}
		return "";
	}
	
	/**
	 * caches an entry
	 * @param      array  $data   The data of an entry
	 */
	protected static function saveData($data){
		if(self::isSerialized($data['meta']))
			$data['meta']=@unserialize($data['meta']);
		else
			$data['meta']=array($data['meta']);
		if(!is_array($data['meta'])) $data['meta']=array();
		self::$menu[$data['ID']]=$data;
	}
	
	
	/**
	 * updates a menu entry
	 * @param      array   $data   The data
	 * @return     boolean  		true if succesfull
	 */
	public static function update($data){
		if(!isset($data['ID'])) return false;
		$id=$data['ID']; //cache id because in query isn't allowed to override
		//override data
		$data=array_merge(self::getEntry($data['ID']),$data);
		foreach ($data as $key => $value) {
			if(!in_array($key, array("name","subgroup","priority","active","meta","href")))
				unset($data[$key]);
		}
		$data['meta']=serialize($data['meta']);
		try {
			self::$db->Update('menu',$data,new dbCond('ID',$id));
			$data['ID']=$id;
			self::saveData($data);
			return true;
		} catch (Exception $e) {
			trigger_error("menu entry couldn't be updated: ".$e->getMessage(),E_USER_WARNING);
			return false;
		}
	}

	/**
	 * adds an menu entry
	 * @param      arrray   $data   The data
	 * @return     boolean  		true if insert was succesfully
	 */
	public static function add($data){
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
			try {
				self::$db->Insert('menu',$data);
				return true;	
			} catch (Exception $e) {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * deletes an menu entry
	 * @param      integer   $id   The identifier
	 */
	public static function delete($id){
		try {
			self::$db->Delete("menu",new dbCond("ID",$id));
			unset(self::$menu[$id]);	
		} catch (Exception $e) {
			trigger_error("menu entry couldn't be deleted: ".$e->getMessage(),E_USER_WARNING);
		}
	}

	/**
	 * Determines if serialized.
	 *
	 * @param      <type>   $string  The string
	 *
	 * @return     boolean  True if serialized, False otherwise.
	 * @license    Copyright (c) 2009 Chris Smith (http://www.cs278.org/)
	 */
	protected static function isSerialized($value){
		if (!is_string($value))
			return false;
		if ($value === 'b:0;')
		{
			$result = false;
			return true;
		}
		$length	= strlen($value);
		$end	= '';
		switch (substr($value,0,1))
		{
			case 's':
				if (substr($value,$length-2,1) !== '"')
					return false;
			case 'b':
			case 'i':
			case 'd':
				$end .= ';';
			case 'a':
			case 'O':
				$end .= '}';
				if (substr($value,1,1) !== ':')
					return false;
				switch (substr($value,2,1))
				{
					case 0:
					case 1:
					case 2:
					case 3:
					case 4:
					case 5:
					case 6:
					case 7:
					case 8:
					case 9:
					break;
					default:
						return false;
				}
			case 'N':
				$end .= ';';
				if (substr($value,$length-1,1) !== $end[0])
					return false;
			break;
			default:
				return false;
		}
		if (($result = @unserialize($value)) === false)
		{
			$result = null;
			return false;
		}
		return true;
	}

}
//initialize the menu that it can be used staticly
menu::checkObject();
?>
