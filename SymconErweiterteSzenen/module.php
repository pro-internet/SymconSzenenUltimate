<?
class UltimateSzenenSteuerung extends IPSModule {

	/////////////
	// Modular  //
	/////////////

	private $docsFileHandle;
	private $docsFile;

	public function __construct($InstanceID) {
		//Never delete this line!
		parent::__construct($InstanceID);
		
		$docsPath = $_ENV['PUBLIC'] . '\Documents\Symcon Modules';
		$docsFile = $docsPath . '\\' . $this->InstanceID . '.json'; 
		if (!file_exists($docsPath)) {
			@mkdir($docsPath, 0777, true);
		}
		if (!file_exists($docsFile)) {
			$fh = @fopen($docsFile, 'w');
			@fclose($fh);
		}

		$this->docsFile = $docsFile;
	}

	public function Create() {
		//Never delete this line!
		parent::Create();

		//Properties
		if(@$this->RegisterPropertyString("Names") !== false)
		{
			$this->RegisterPropertyString("Names", "");
			$this->RegisterPropertyInteger("Sensor", 0);
			$this->CreateSetValueScript($this->InstanceID);
		}

		if(!IPS_VariableProfileExists("SZS.SceneControl")){
			IPS_CreateVariableProfile("SZS.SceneControl", 1);
			IPS_SetVariableProfileValues("SZS.SceneControl", 1, 2, 0);
			//IPS_SetVariableProfileIcon("SZS.SceneControl", "");
			IPS_SetVariableProfileAssociation("SZS.SceneControl", 1, "Speichern", "", -1);
			IPS_SetVariableProfileAssociation("SZS.SceneControl", 2, "Ausführen", "", -1);
		}

		if(!IPS_VariableProfileExists("Switch")){
			 // Create Switch Profile if its not exists
			 IPS_CreateVariableProfile("Switch",0);
			 IPS_SetVariableProfileValues("Switch",0,1,1);
			 IPS_SetVariableProfileAssociation("Switch",0,"Aus","",-1);
			 IPS_SetVariableProfileAssociation("Switch",1,"An","", 0x8000FF);
			 IPS_SetVariableProfileIcon("Switch","Power");

			 IPS_SetVariableCustomProfile($vid,"Switch");
		}

	}

	public function Destroy() {
		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();

		$this->RemoveExcessiveProfiles("ESZS.Selector");
		$this->RemoveExcessiveProfiles("ESZS.Sets");
		
        //Create Targets Dummy Instance
		if(@IPS_GetObjectIDByIdent("Targets", IPS_GetParent($this->InstanceID)) === false)
		{
			$DummyGUID = $this->GetModuleIDByName();
			$insID = IPS_CreateInstance($DummyGUID);
			$dummyExisted = false;
		}
		else
		{
			$insID = IPS_GetObjectIDByIdent("Targets", IPS_GetParent($this->InstanceID));
			$dummyExisted = true;
		}
		IPS_SetName($insID, "Targets");
		IPS_SetParent($insID, IPS_GetParent($this->InstanceID));
		IPS_SetPosition($insID, 9999);
		IPS_SetIdent($insID, "Targets");

		//See if theres an old Targets Folder
		if(@IPS_GetObjectIDByIdent("Targets", $this->InstanceID) !== false)
		{
			//Resolve Update->Downgrade Patch discrepancy || Delete excessive targets
			if($dummyExisted)
			{
				foreach(IPS_GetChildrenIDs($insID) as $chID)
				{
					$this->Del($chID);
				}
			}
			//move targets of "Targets"-Folder into "Targets"-Dummy Instance
			$targetsID = IPS_GetObjectIDByIdent("Targets", $this->InstanceID);
			foreach(IPS_GetChildrenIDs($targetsID) as $targetLinkID)
			{
				$content = array_merge(IPS_GetObject($targetLinkID), IPS_GetLink($targetLinkID));
				$content["ParentID"] = $insID;
				$this->CreateLink($content);
			}
            $this->Del($targetsID);
		}
		
		//$this->CreateCategoryByIdent($this->InstanceID, "Targets", "Targets");
		$data = json_decode($this->ReadPropertyString("Names"),true);

		if($data != "")
		{
			IPS_SetPosition($this->InstanceID, -700);

			//copy Values of the Sets and Selector Profile
			if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) !== false)
			{
				$setSavedValues = array();
				$setIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
				foreach(IPS_GetChildrenIDs($setIns) as $l => $child)
				{
					$setSavedValues[$l]['obj'] = IPS_GetObject($child);
					$setSavedValues[$l]['var'] = IPS_GetVariable($child);
					$setSavedValues[$l]['var']['Value'] = GetValue($child);
					$setSavedValues[$l]['profile'] = IPS_GetVariableProfile("ESZS.Selector" . $this->InstanceID);
				}
			}
			
			//check if the scenes were already created with IDs but it was patched down
			if($data[0]['ID'] == null || $data[0]['ID'] == 0)
			{
				foreach(IPS_GetChildrenIDs($this->InstanceID) as $i => $child)
				{
					$ident = IPS_GetObject($child)['ObjectIdent'];
					if(strpos($ident, "Scene") !== false && strpos($ident, "Data") !== true)
					{
						$ID = str_replace("Scene", "", $ident);
						if($ID > 9999)
						{
							$configModule = json_decode(IPS_GetConfiguration($this->InstanceID), true);
							$data[$i]['ID'] = $ID;
							$configModule['Names'] = json_encode($data);
							$configJSON = json_encode($configModule);
							IPS_SetConfiguration($this->InstanceID, $configJSON);
							IPS_ApplyChanges($this->InstanceID);
						}
					}
				}
			}

			//basically check if it's an update and keep the current scenes alive
			$update = false;
			foreach(IPS_GetChildrenIDs($this->InstanceID) as $child)
			{
				$ident = IPS_GetObject($child)['ObjectIdent'];
				if(strpos($ident, "Scene") !== false && strpos($ident, "Data") !== true)
				{
					$sceneNum = str_replace("Scene", "", $ident);
					if($sceneNum < 9999)
					{
						$update = true;
						$configModule = json_decode(IPS_GetConfiguration($this->InstanceID), true);
						$ID = rand(10000, 99999);
						$data[$sceneNum - 1]['ID'] = $ID;
						$configModule['Names'] = json_encode($data);
						$configJSON = json_encode($configModule);
						//change the properties of the scene to the new ones
						IPS_SetIdent($child, "Scene$ID");
						$dataID = IPS_GetObjectIDByIdent("Scene$sceneNum" . "Data", $this->InstanceID);
						IPS_SetIdent($dataID, "Scene$ID" . "Data");
					}
				}
			}
			if($update)
			{
				IPS_SetConfiguration($this->InstanceID, $configJSON);
				IPS_ApplyChanges($this->InstanceID);
			}
			
			//Selector profile
			if(IPS_VariableProfileExists("ESZS.Selector" . $this->InstanceID))
			{
				IPS_DeleteVariableProfile("ESZS.Selector" . $this->InstanceID);
				IPS_CreateVariableProfile("ESZS.Selector" . $this->InstanceID, 1);
				IPS_SetVariableProfileIcon("ESZS.Selector" . $this->InstanceID, "Rocket");
			}
			else
			{
				IPS_CreateVariableProfile("ESZS.Selector" . $this->InstanceID, 1);
				IPS_SetVariableProfileIcon("ESZS.Selector" . $this->InstanceID, "Rocket");
			}

			//Events Category
			if(@IPS_GetObjectIDByIdent("EventsCat", $this->InstanceID) === false)
			{
				$eventsCat = IPS_CreateCategory();
				IPS_SetName($eventsCat, "Events");
				IPS_SetIdent($eventsCat, "EventsCat");
				IPS_SetParent($eventsCat, $this->InstanceID);
				IPS_SetHidden($eventsCat, true);
			}
			else
			{
				$eventsCat = IPS_GetObjectIDByIdent("EventsCat", $this->InstanceID);
			}
			
			$noPos = true;
			foreach($data as $d)
			{
				if($d['Position'] !== 0)
				{
					$noPos = false;
				}
			}
			if($noPos === false)
				$data = $this->sortByKey($data, "Position");		
			else
			{
				foreach($data as $l => $d)
				{
					$data[$l]['Position'] = $l;
				}
				$configModule['Names'] = json_encode($data);
				$configJSON = json_encode($configModule);
				IPS_SetConfiguration($this->InstanceID, $configJSON);
				IPS_ApplyChanges($this->InstanceID);
				$standBy = true;
			}
			
			$standBy = false;
			if($update === false)
			{
				for($i = 0; $i < sizeof($data); $i++)
				{
					$data = json_decode($this->ReadPropertyString("Names"),true);
					$data = $this->sortByKey($data, "Position");
					if($noPos)
						$data[$i]['Position'] = $i;
					$ID = @$data[$i]['ID'];
					//Set Configuration for new Scenes
					if($ID == 0 || $ID == null)
					{
						$configModule = json_decode(IPS_GetConfiguration($this->InstanceID), true);
						$ID = rand(10000, 99999);
						$data[$i]['ID'] = $ID;
						$configModule['Names'] = json_encode($data);
						$configJSON = json_encode($configModule);
						IPS_SetConfiguration($this->InstanceID, $configJSON);
						IPS_ApplyChanges($this->InstanceID);
						$standBy = true;
						break;
					}
					
					if(@IPS_GetObjectIDByIdent("Scene".$ID, $this->InstanceID) === false){
						//Scene
						$vid = IPS_CreateVariable(1 /* Scene */);
						IPS_LogMessage("DaySet_Scenes", "Creating new Scene Variable...");
						SetValue($vid, 2);
					} else
					{
						$vid = IPS_GetObjectIDByIdent("Scene".$ID, $this->InstanceID);
					}
					IPS_SetParent($vid, $this->InstanceID);
					IPS_SetName($vid, $data[$i]['name']);
					IPS_SetIdent($vid, "Scene".$ID);
					IPS_SetPosition($vid, $data[$i]['Position']);
					IPS_SetVariableCustomProfile($vid, "SZS.SceneControl");
					$this->EnableAction("Scene".$ID);

					if(@IPS_GetObjectIDByIdent("Scene".$ID."Data", $this->InstanceID) === false)
					{
						//SceneData
						$vid = IPS_CreateVariable(3 /* SceneData */);
						IPS_LogMessage("DaySet_Scenes", "Creating new SceneData Variable...");
					}
					else
					{
						$vid = IPS_GetObjectIDByIdent("Scene".$ID."Data", $this->InstanceID);
					}
					IPS_SetParent($vid, $this->InstanceID);
					IPS_SetName($vid, $data[$i]['name']."Data");
					IPS_SetIdent($vid, "Scene".$ID."Data");
					IPS_SetPosition($vid, $data[$i]['Position'] + $this->maxByKey($data, 'Position') + 1);
					IPS_SetHidden($vid, true);

					//Set Selector profile
					IPS_SetVariableProfileAssociation("ESZS.Selector" . $this->InstanceID, ($i), $data[$i]['name'],"",-1);
				}
			}

			//fix newly added scenes breaking "Sets" presets
			if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) !== false)
			{	
				$setIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
				foreach(IPS_GetChildrenIDs($setIns) as $l => $child)
				{
					$o = IPS_GetObject($child);
					if(isset($setSavedValues))
					{
						foreach($setSavedValues as $stateSavedValues)
						{
							if($stateSavedValues['obj']['ObjectIdent'] == $o['ObjectIdent'])
							{
								$savedName = @$stateSavedValues['profile']['Associations'][$stateSavedValues['var']['Value']]['Name'];
								$currentName = @IPS_GetVariableProfile("ESZS.Selector" . $this->InstanceID)['Associations'][GetValue($child)]['Name'];
								if($savedName != $currentName)
								{
									$assoc = $this->GetAssociationByName("ESZS.Selector" . $this->InstanceID, $savedName);
									if($assoc !== false)
										SetValue($child, $assoc);
								}
							}
						}
					}
				}
			}

			//Selector Variable
			if(@IPS_GetObjectIDByIdent("Selector", IPS_GetParent($this->InstanceID)) === false)
			{
				$vid = IPS_CreateVariable(1);
			}
			else
			{
				$vid = IPS_GetObjectIDByIdent("Selector", IPS_GetParent($this->InstanceID));
			}
			$svs = IPS_GetObjectIDByIdent("SetValueScript", $this->InstanceID);
			IPS_SetParent($vid, IPS_GetParent($this->InstanceID));
			IPS_SetName($vid, IPS_GetName($this->InstanceID));
			IPS_SetIdent($vid, "Selector");
			IPS_SetPosition($vid, 600);
			IPS_SetVariableCustomProfile($vid, "ESZS.Selector" . $this->InstanceID);
			IPS_SetVariableCustomAction($vid, $svs);

			//Create Selector event
			if(@IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat) === false)
			{
				$eid = IPS_CreateEvent(0);
			}
			else
			{
				$eid = IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat);
			}
			IPS_SetParent($eid, $eventsCat);
			IPS_SetName($eid, "Selector.OnChange");
			IPS_SetIdent($eid, "SelectorOnChange");
			IPS_SetEventTrigger($eid, 0, $vid);
			IPS_SetEventActive($eid, true);
			IPS_SetEventScript($eid, "ESZS_CallScene(". $this->InstanceID .", GetValue($vid));");

			//Create Sensor event
			$sensorID = $this->ReadPropertyInteger("Sensor");
			if($sensorID > 9999)
				$sensorExists = true;
			else
				$sensorExists = false;
			if($sensorExists)
			{
				if(@IPS_GetObjectIDByIdent("SensorEvent", $eventsCat) === false)
				{
					$eid = IPS_CreateEvent(0);
				}
				else
				{
					$eid = IPS_GetObjectIDByIdent("SensorEvent", $eventsCat);
				}
				IPS_SetEventTrigger($eid, 1, $sensorID);
				IPS_SetEventScript($eid, "ESZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
				IPS_SetEventActive($eid, true);
				IPS_SetParent($eid, $eventsCat);
				IPS_SetName($eid, "Sensor.OnChange");
				IPS_SetIdent($eid, "SensorEvent");
			}

			//Create Automatik for this instance
			if(@IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID)) === false)
				$vid = IPS_CreateVariable(0);
			else
				$vid = IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID));
			IPS_SetName($vid, "Automatik");
			IPS_SetParent($vid, IPS_GetParent($this->InstanceID));
			IPS_SetPosition($vid, -999);
			IPS_SetIdent($vid, "Automatik");
			IPS_SetVariableCustomAction($vid, $svs);
			IPS_SetVariableCustomProfile($vid, "Switch");

			//Create Event for Automatik
			if(@IPS_GetObjectIDByIdent("AutomatikEvent", $eventsCat) === false)
				$eid = IPS_CreateEvent(0);
			else
				$eid = IPS_GetObjectIDByIdent("AutomatikEvent", $eventsCat);
			IPS_SetEventTrigger($eid, 4, $vid);
			IPS_SetEventTriggerValue($eid, true);
			IPS_SetEventScript($eid, "ESZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
			IPS_SetEventActive($eid, true);
			IPS_SetParent($eid, $eventsCat);
			IPS_SetName($eid, "Automatik.OnTrue");
			IPS_SetIdent($eid, "AutomatikEvent");

			//Create Sperre for this instance
			if(@IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID)) === false)
				$vid = IPS_CreateVariable(0);
			else
				$vid = IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID));
			IPS_SetName($vid, "Sperre");
			IPS_SetParent($vid, IPS_GetParent($this->InstanceID));
			IPS_SetPosition($vid, -999);
			IPS_SetIdent($vid, "Sperre");
			IPS_SetVariableCustomAction($vid, $svs);
			IPS_SetVariableCustomProfile($vid, "Switch");

			//Create Event for Sperre
			if(@IPS_GetObjectIDByIdent("SperreEvent", $eventsCat) === false)
				$eid = IPS_CreateEvent(0);
			else
				$eid = IPS_GetObjectIDByIdent("SperreEvent", $eventsCat);
			IPS_SetEventTrigger($eid, 4, $vid);
			IPS_SetEventTriggerValue($eid, false);
			IPS_SetEventScript($eid, "ESZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
			IPS_SetEventActive($eid, true);
			IPS_SetParent($eid, $eventsCat);
			IPS_SetName($eid, "Sperre.OnFalse");
			IPS_SetIdent($eid, "SperreEvent");

			//Create Sensor Selection
			//by its profile
			// $sensorID = this->ReadPropertyInteger("Sensor");
			// if($sensorID > 9999)
			// {
				// $profileName = IPS_GetVariable($sensorID)['VariableProfile'];
				// if($profileName == "")
					// $profileName = IPS_GetVariable($sensorID)['VariableCustomProfile'];
				// $profile = IPS_GetVariableProfile($profileName);

			// }

			//Create all the states (Morgen, Tag...)
			$sensorID = $this->ReadPropertyInteger("Sensor");
			if($sensorID > 9999)
			{
				if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) === false)
				{
					$DummyGUID = $this->GetModuleIDByName();
					$insID = IPS_CreateInstance($DummyGUID);
				}
				else
				{
					$insID = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
				}
				IPS_SetName($insID, "DaySets");
				IPS_SetParent($insID, IPS_GetParent($this->InstanceID));
				IPS_SetPosition($insID, -500);
				IPS_SetIdent($insID, "Set");

				$sets = array("Früh","Morgen","Tag","Dämmerung","Abend","Nacht");
				//Create the profile
				if(IPS_VariableProfileExists("ESZS.Sets" . $this->InstanceID))
					IPS_DeleteVariableProfile("ESZS.Sets" . $this->InstanceID);

				//Create the variables
				foreach($sets as $i => $state)
				{
					if(@IPS_GetObjectIDByIdent("set$i", $insID) === false)
						$vid = IPS_CreateVariable(1);
					else
						$vid = IPS_GetObjectIDByIdent("set$i", $insID);
					IPS_SetName($vid, $state);
					IPS_SetParent($vid, $insID);
					IPS_SetPosition($vid, $i);
					IPS_SetIdent($vid, "set$i");
					IPS_SetVariableCustomAction($vid, $svs);
					IPS_SetVariableCustomProfile($vid, "ESZS.Selector" . $this->InstanceID);

					//Create Events for the States
					if(@IPS_GetObjectIDByIdent("SetEvent$i", $eventsCat) === false)
						$eid = IPS_CreateEvent(0);
					else
						$eid = IPS_GetObjectIDByIdent("SetEvent$i", $eventsCat);
					IPS_SetEventTrigger($eid, 1, $vid);
					IPS_SetEventScript($eid, "ESZS_CallScene(" . $this->InstanceID . ", ($sensorID *100));");
					IPS_SetName($eid, "$state".".OnChange");
					IPS_SetParent($eid, $eventsCat);
					IPS_SetIdent($eid, "SetEvent$i");
					IPS_SetEventActive($eid, true);
				}
			}

			//Delete excessive Scences
			if($standBy === false && $update === false)
			{
				$ChildrenIDs = IPS_GetChildrenIDs($this->InstanceID);
				foreach($ChildrenIDs as $child)
				{
					$ident = IPS_GetObject($child)['ObjectIdent'];
					if(strpos($ident, "Scene") !== false)
					{
						$entryExists = false;
						foreach($data as $j => $entry)
						{
							if(str_replace("Scene", "", $ident) == $entry['ID'])
							{
								$entryExists = true;
							}
						}
						if($entryExists == false)
						{
							//copy values of older version of the module to the new one (shouldn't occur at all)
							$excessiveID = str_replace("Scene", "", $ident);
							$excessiveID = str_replace("Data", "", $excessiveID);
							if($excessiveID < 9999)
							{
								IPS_LogMessage("DaySet_Scenes", "copy values of an older version of the module to the new one");
								foreach(IPS_GetChildrenIDs($this->InstanceID) as $c)
								{
									if(IPS_GetName($c) == IPS_GetName($child) && $c != $child)
									{
										SetValue($c, GetValue($child));
									}
								}
							}
							IPS_DeleteVariable($child);
						}
					}
				}
			}
			//Delete Excessive Automation and DaySets
			$sensor = $this->ReadPropertyInteger("Sensor");
			if($sensor < 9999)
			{
				if(@IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID)) !== false)
				{
					$autoVar = IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID));
					IPS_DeleteVariable($autoVar);
				}

				if(@IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID)) !== false)
				{
					$sperreVar = IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID));
					IPS_DeleteVariable($sperreVar);
				}

				if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) !== false)
				{
					$setIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
					$this->Del($setIns);
				}
			}
		}
	}

	public function RequestAction($Ident, $Value) {

		switch($Value) {
			case "1":
				$this->SaveValues($Ident);
				break;
			case "2":
				$this->CallValues($Ident);
				break;
			default:
				throw new Exception("Invalid action");
		}
	}

	public function CallScene(int $SceneNumber){
		if($SceneNumber > 99999) //sender = Sensor
		{
			$SceneNumber = floor($SceneNumber/100);
			$sensorWert = GetValue($SceneNumber) - 1;
			$setsIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
			$set = IPS_GetObjectIDByIdent("set$sensorWert", $setsIns);
			$ActualSceneNumber = GetValue($set);
			//Get Scene Identity by Association name 
			$assoc = IPS_GetVariableProfile("ESZS.Selector" . $this->InstanceID)['Associations'][$ActualSceneNumber];
			$sceneVar = IPS_GetObjectIDByName($assoc['Name'], $this->InstanceID);
			$actualIdent = IPS_GetObject($sceneVar)['ObjectIdent'];
			$this->CallValues($actualIdent."Sensor");
		}
		else
		{
			if($SceneNumber < 10000) //sender = selector
			{
				$assoc = IPS_GetVariableProfile("ESZS.Selector" . $this->InstanceID)['Associations'][$SceneNumber];
				$sceneVar = IPS_GetObjectIDByName($assoc['Name'], $this->InstanceID);
				$actualIdent = IPS_GetObject($sceneVar)['ObjectIdent'];
				$this->CallValues($actualIdent);
			}
			else
			{
				$this->CallValues("Scene".$SceneNumber);
			}
		}
	}

	public function SaveScene(int $SceneNumber){

		$this->SaveValues("Scene".$SceneNumber);

	}

	private function SaveValues($SceneIdent) {

		$targetIDs = IPS_GetObjectIDByIdent("Targets", IPS_GetParent($this->InstanceID));
		$data = Array();

		IPS_LogMessage("DaySet_Scenes.SaveValues", "Saving new Scene Data Values...");
		IPS_LogMessage("DaySet_Scenes.SaveValues", "Targets from ". IPS_GetName(IPS_GetParent($targetIDs)) ."/". IPS_GetName($targetIDs) ." ($targetIDs) are being used)");
		

		//We want to save all Lamp Values
		foreach(IPS_GetChildrenIDs($targetIDs) as $TargetID) {
			//only allow links
			if(IPS_LinkExists($TargetID)) {
				$linkVariableID = IPS_GetLink($TargetID)['TargetID'];
				if(IPS_VariableExists($linkVariableID)) {
					$data[$linkVariableID] = GetValue($linkVariableID);
				}
			}
		}
		$sceneDataID = IPS_GetObjectIDByIdent($SceneIdent."Data", $this->InstanceID);
		$sceneData = wddx_serialize_value($data);
		SetValue($sceneDataID, $sceneData);

		//write into backup file
		try
		{
			$content = json_decode(@file_get_contents($this->docsFile), true);
			$content[$sceneDataID] = $sceneData;
			@file_put_contents($this->docsFile, json_encode($content));
		} catch (Exception $e) { 
			IPS_LogMessage("DaySet_Scenes.SaveValues", "couldn't access backup file: " . $e->getMessage());
		}
	}

	private function CallValues($SceneIdent) {

		$actualIdent = str_replace("Sensor", "", $SceneIdent);
		$selectValue = str_replace("Scene", "", $actualIdent);
		$sceneDataID = IPS_GetObjectIDByIdent($SceneIdent."Data", $this->InstanceID);
		$dataStr = GetValue($sceneDataID);
		$data = wddx_deserialize($dataStr);
		if($data != NULL && strlen($dataStr) > 3) {
			//write into backup file after calling a scene
			try {
				$content = json_decode(@file_get_contents($this->docsFile), true);
				$content[$sceneDataID] = $dataStr;
				@file_put_contents($this->docsFile, json_encode($content));
			} catch (Exception $e) { 
				IPS_LogMessage("DaySet_Scenes.CallValues", "couldn't access backup file: " . $e->getMessage());
			}

			if(strpos($SceneIdent, "Sensor") !== false)
			{
				if(@IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID)) !== false)
				{
					$automatikID = IPS_GetObjectIDByIdent("Automatik", IPS_GetParent($this->InstanceID));
					$auto = GetValue($automatikID);
				}

				if(@IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID)) !== false)
				{
					$SperreID = IPS_GetObjectIDByIdent("Sperre", IPS_GetParent($this->InstanceID));
					$sperre = GetValue($SperreID);
				}
			}
			else
			{
				$auto = true;
				$sperre = false;
			}
			if($auto && !$sperre)
			{
				//Set Selector to current Scene
				$selectVar = IPS_GetObjectIDByIdent("Selector", IPS_GetParent($this->InstanceID));
				$eventsCat = IPS_GetObjectIDByIdent("EventsCat", $this->InstanceID);
				$selectEvent = IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat);
				IPS_SetEventActive($selectEvent, false);
				IPS_Sleep(100);
				$sceneVar = IPS_GetObjectIDByIdent($actualIdent, $this->InstanceID);
				$sceneNum = $this->GetAssociationByName("ESZS.Selector" . $this->InstanceID, IPS_GetObject($sceneVar)['ObjectName']);
				SetValue($selectVar, $sceneNum);
				IPS_Sleep(100);
				IPS_SetEventActive($selectEvent, true);

				IPS_LogMessage("DaySet_Scenes.CallValues", "Calling Values for Scene '".IPS_GetName($sceneVar)."'");
				//Set the actual values for the targets
				foreach($data as $id => $value) {
					if (IPS_VariableExists($id)){

						$o = IPS_GetObject($id);
						$v = IPS_GetVariable($id);

						if($v['VariableCustomAction'] > 0)
							$actionID = $v['VariableCustomAction'];
						else
							$actionID = $v['VariableAction'];

						//Skip this device if we do not have a proper id
						if($actionID < 10000)
						{
							SetValue($id, $value);
							continue;
						}

						if(IPS_InstanceExists($actionID)) {
							IPS_RequestAction($actionID, $o['ObjectIdent'], $value);
						} else if(IPS_ScriptExists($actionID)) {
							echo IPS_RunScriptWaitEx($actionID, Array("VARIABLE" => $id, "VALUE" => $value, "SENDER" => "WebFront"));
						}
					}
				}
			}
		} else {
			$sceneDataID = IPS_GetObjectIDByIdent($actualIdent."Data", $this->InstanceID);
			$data = GetValue($sceneDataID);
			//Scene Data Variable is completely empty, as in never saved any values to it
			if(strlen($data) < 3)
			{
				try {
					$content = json_decode(@file_get_contents($this->docsFile), true);
					if(array_key_exists($sceneDataID, $content))
					{
						if(strlen($content[$sceneDataID]) > 3)
						{
							SetValue($sceneDataID, $content[$sceneDataID]);
							$this->CallValues($SceneIdent);
						}
					}
					else
					{
						echo "No SceneData for this Scene";
					}
				} catch (Exception $e) { 
					IPS_LogMessage("DaySet_Scenes.CallValues", "couldn't access backup file when SceneData was empty: " . $e->getMessage());
				}
			}
			else
			{
				echo "No SceneData for this Scene";
			}
		}
	}

	private function CreateCategoryByIdent($id, $ident, $name) {
		 $cid = @IPS_GetObjectIDByIdent($ident, $id);
		 if($cid === false) {
			 $cid = IPS_CreateCategory();
			 IPS_SetParent($cid, $id);
			 IPS_SetName($cid, $name);
			 IPS_SetIdent($cid, $ident);
		 }
		 return $cid;
	}

	private function CreateSetValueScript($parentID)
	{
		if(@IPS_GetObjectIDByIdent("SetValueScript", $parentID) === false)
		{
			$sid = IPS_CreateScript(0 /* PHP Script */);
		}
		else
		{
			$sid = IPS_GetObjectIDByIdent("SetValueScript", $parentID);
		}
		IPS_SetParent($sid, $parentID);
			IPS_SetName($sid, "SetValue");
			IPS_SetIdent($sid, "SetValueScript");
			IPS_SetHidden($sid, true);
			IPS_SetPosition($sid, 99999);
			IPS_SetScriptContent($sid, "<?
SetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);
?>");
		return $sid;
	}

	protected function GetModuleIDByName($name = "Dummy Module")
	{
		$moduleList = IPS_GetModuleList();
		$GUID = ""; //init
		foreach($moduleList as $l)
		{
			if(IPS_GetModule($l)['ModuleName'] == $name)
			{
				$GUID = $l;
				break;
			}
		}
		return $GUID;
	}

	private function CreateAutomatikSwitch($targetFolder)
	{
		if($this->ReadPropertyInteger("Sensor") > 9999)
		{
			if(@IPS_GetObjectIDByIdent("AutomatikIns", IPS_GetParent($this->InstanceID)) === false)
			{
				$dummyGUID = $this->GetModuleIDByName();
				$insID = IPS_CreateInstance($dummyGUID);
			}
			else
			{
				$insID = IPS_GetObjectIDByIdent("AutomatikIns", IPS_GetParent($this->InstanceID));
			}
			IPS_SetName($insID, "Automatik");
			IPS_SetParent($insID, IPS_GetParent($this->InstanceID));
			IPS_SetIdent($insID, "AutomatikIns");
			$svs = IPS_GetObjectIDByIdent("SetValueScript", $this->InstanceID);

			$targets = IPS_GetChildrenIDs($targetFolder);
			foreach($targets as $targetLink)
			{
				$target = IPS_GetLink($targetLink)['TargetID'];
				$ident = IPS_GetObject($target)['ObjectIdent'];
				if(strpos($ident, "Automatik") === false)
				{
					//Create the Variables
					if(@IPS_GetObjectIDByIdent("$target"."Automatik", $insID) === false)
					{
						$vid = IPS_CreateVariable(0);
					}
					else
					{
						$vid = IPS_GetObjectIDByIdent("$target"."Automatik", $insID);
					}
					IPS_SetName($vid, IPS_GetName($target));
					IPS_SetParent($vid, $insID);
					IPS_SetIdent($vid, "$target"."Automatik");
					IPS_SetVariableCustomProfile($vid, "Switch");
					IPS_SetVariableCustomAction($vid, $svs);

					//Create the Target Links
					if(@IPS_GetObjectIDByIdent("$target"."AutomatikLink", $targetFolder) === false)
					{
						$lid = IPS_CreateLink();
					}
					else
					{
						$lid = IPS_GetObjectIDByIdent("$target"."AutomatikLink", $targetFolder);
					}
					IPS_SetName($lid, IPS_GetName($target) . ".Automatik");
					IPS_SetParent($lid, $targetFolder);
					IPS_SetIdent($lid, "$target"."AutomatikLink");
					IPS_SetLinkTargetID($lid, $vid);
				}
			}
			return $insID;
		}
	}
	
	private function GetAssociationByName($profile, $name)
	{
		$associations = IPS_GetVariableProfile($profile)['Associations'];
		foreach($associations as $i => $assoc)
		{
			if($assoc['Name'] == $name)
			{
				return $i;
			}
		}
		return false;
	}

	private function RemoveExcessiveProfiles($profileName)
	{
		$profiles = IPS_GetVariableProfileListByType(1);
		foreach($profiles as $key => $profile)
		{
			if(strpos($profile, "$profileName") !== false)
			{
				$id = (int) str_replace("$profileName", "", $profile);
				if(!IPS_InstanceExists($id))
				{
					IPS_DeleteVariableProfile($profile);
				}
			}
		}
	}
	
	private function maxByKey($arr, $k)
	{
		$d = array();
		foreach ($arr as $key => $row)
		{
			$d[$key] = $row[$k];
		}
		array_multisort($arr, SORT_DESC, $d);
		return $arr[0]['Position'];
	}
	
	private function sortByKey($arr, $k)
	{
		$d = array();
		foreach ($arr as $key => $row)
		{
			$d[$key] = $row[$k];
			
		}
		array_multisort($arr, SORT_ASC, $d);
		return $arr;
	}

    protected function CreateLink($content)
	{
		/**
		 * 
		 * 
		 * @param <array> $content 
		 * 
		 * @return <integer> $LinkID
		 
		$content = array("ObjectName" => "LinkName",
						 "ParentID" => ParentID,
						 "ObjectIdent" => "Identity",
						 "TargetID" => TargetID,
						 "ObjectInfo" => "Info", //optional
						 "ObjectIsHidden" => Boolean, //optional
						 "ObjectPosition" => position, //optional
						 "ObjectIcon" => "Icon" //optional
						)
		 */
		if(@IPS_GetObjectIDByIdent($content["ObjectIdent"], $content["parentID"]) === false)
		{
			$id = IPS_CreateLink();
			IPS_SetName($id, $content['ObjectName']);
			IPS_SetParent($id, $content['ParentID']);
			IPS_SetIdent($id, $content['ObjectIdent']);
			if(array_key_exists("ObjectInfo", $content))
				IPS_SetInfo($id, $content["ObjectInfo"]);
			if(array_key_exists("ObjectIsHidden", $content))
				IPS_SetHidden($id, $content["ObjectIsHidden"]);
			if(array_key_exists("ObjectPosition", $content))
				IPS_SetPosition($id, $content["ObjectPosition"]);
			if(array_key_exists("ObjectIcon", $content))
				IPS_SetIcon($id, $content["ObjectIcon"]);
			IPS_SetLinkTargetID($id, $content["TargetID"]);
		}
		else
		{
			$id = IPS_GetObjectIDByIdent($content["ObjectIdent"], $content["ParentID"]);
		}
		return $id;
	}


    protected function Del($id, $bool = false /*Delete associated files along with the objects ?*/)
	{
		if(IPS_HasChildren($id))
		{
			$childIDs = IPS_GetChildrenIDs($id);
			foreach($childIDs as $child)
			{
				$this->Del($child);
			}
			$this->Del($id);
		}
		else
		{
			$type = IPS_GetObject($id)['ObjectType'];
			switch($type)
			{
				case(0):
					IPS_DeleteCategory($id);
					break;
				case(1):
					IPS_DeleteInstance($id);
					break;
				case(2):
					IPS_DeleteVariable($id);
					break;
				case(3):
					IPS_DeleteScript($id);
					break;
				case(4):
					IPS_DeleteEvent($id);
					break;
				case(5):
					IPS_DeleteMedia($id, $bool /*dont delete media file along with it*/);
					break;
				case(6):
					IPS_DeleteLink($id);
			}
		}
	}
}
?>
