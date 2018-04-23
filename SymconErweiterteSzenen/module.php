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
			$this->RegisterPropertyBoolean("ModeDaySet", true);
			$this->RegisterPropertyBoolean("ModeTime", false);
			$this->RegisterPropertyBoolean("Loop", false);
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
			 // Create Switch Profile if it does not exist
			 IPS_CreateVariableProfile("Switch",0);
			 IPS_SetVariableProfileValues("Switch",0,1,1);
			 IPS_SetVariableProfileAssociation("Switch",0,"Aus","",-1);
			 IPS_SetVariableProfileAssociation("Switch",1,"An","", 0x8000FF);
			 IPS_SetVariableProfileIcon("Switch","Power");

			 IPS_SetVariableCustomProfile($vid,"Switch");
		}

		if(!IPS_VariableProfileExists("SZS.Minutes")){
			// Create Minutes Profile if it does not exist
			IPS_CreateVariableProfile("SZS.Minutes", 1);
			IPS_SetVariableProfileValues("SZS.Minutes", 0, 120, 1);
			IPS_SetVariableProfileText("SZS.Minutes",""," Min.");
			//IPS_SetVariableProfileIcon("SZS.Minutes", "");
		}

		if(!IPS_VariableProfileExists("SZS.StartStopButton")){
			// Create Start/Stop Button Profile if it does not exist
			IPS_CreateVariableProfile("SZS.StartStopButton", 1);
			IPS_SetVariableProfileValues("SZS.StartStopButton", 1, 1, 0);
			//IPS_SetVariableProfileIcon("SZS.StartStopButton", "");
			IPS_SetVariableProfileAssociation("SZS.StartStopButton", 1, "Start", "", -1);
		}

	}

	public function Destroy() {
		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();

		$this->RemoveExcessiveProfiles("USZS.Selector");
		$this->RemoveExcessiveProfiles("USZS.Sets");

		// Create Events Category
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
		
        // Create Targets Dummy Instance
		if(@IPS_GetObjectIDByIdent("Targets", IPS_GetParent($this->InstanceID)) === false)
		{
			$DummyGUID = $this->GetModuleIDByName();
			$insID = IPS_CreateInstance($DummyGUID);
		}
		else
		{
			$insID = IPS_GetObjectIDByIdent("Targets", IPS_GetParent($this->InstanceID));
		}
		IPS_SetName($insID, "Targets");
		IPS_SetParent($insID, IPS_GetParent($this->InstanceID));
		IPS_SetPosition($insID, 9999);
		IPS_SetIdent($insID, "Targets");

		$data = json_decode($this->ReadPropertyString("Names"),true);
		if($data != "")
		{
			//all the general things, that need to be included in all modes
			{
				IPS_SetPosition($this->InstanceID, -700);
				
				//resolve the Positioning of the Scenes
				{
					// decide whether to set the position by Creation Order or by Custom (user) order
					$noPos = true;
					foreach($data as $d)
					{
						if($d['Position'] !== 0)
						{
							$noPos = false;
						}
					}
					// sort the dataset accordingly
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
						return;
					}
				}

				// copy Values of the Sets and Selector Profile if they already exist
				// will only trigger if the mode is set to DaySets
				if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) !== false)
				{
					$setSavedValues = array();
					$setIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
					foreach(IPS_GetChildrenIDs($setIns) as $l => $child)
					{
						$setSavedValues[$l]['obj'] = IPS_GetObject($child);
						$setSavedValues[$l]['var'] = IPS_GetVariable($child);
						$setSavedValues[$l]['var']['Value'] = GetValue($child);
						$setSavedValues[$l]['profile'] = IPS_GetVariableProfile("USZS.Selector" . $this->InstanceID);
					}
				}

				// Create (raw) Selector profile
				if(IPS_VariableProfileExists("USZS.Selector" . $this->InstanceID))
				{
					IPS_DeleteVariableProfile("USZS.Selector" . $this->InstanceID);
					IPS_CreateVariableProfile("USZS.Selector" . $this->InstanceID, 1);
					IPS_SetVariableProfileIcon("USZS.Selector" . $this->InstanceID, "Rocket");
				}
				else
				{
					IPS_CreateVariableProfile("USZS.Selector" . $this->InstanceID, 1);
					IPS_SetVariableProfileIcon("USZS.Selector" . $this->InstanceID, "Rocket");
				}

				//Create all associated Variables and Configure the "USZS.Selector" Profile accordingly
				for($i = 0; $i < sizeof($data); $i++)
				{
					$data = json_decode($this->ReadPropertyString("Names"),true);
					$data = $this->sortByKey($data, "Position");
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
						return;
					}
					
					//Create Scene Variable
					if(@IPS_GetObjectIDByIdent("Scene".$ID, $this->InstanceID) === false){
						//Scene
						$vid = IPS_CreateVariable(1 /* int */);
						IPS_LogMessage("SceneModule", "Creating new Scene Variable...");
						SetValue($vid, 2);
					} else
					{
						$vid = IPS_GetObjectIDByIdent("Scene".$ID, $this->InstanceID);
					}
					IPS_SetParent($vid, $this->InstanceID);
					IPS_SetName($vid, $data[$i]['name']);
					IPS_SetIdent($vid, "Scene".$ID);
					IPS_SetPosition($vid, $data[$i]['Position'] * 3);
					IPS_SetVariableCustomProfile($vid, "SZS.SceneControl");
					$this->EnableAction("Scene".$ID);

					//Create SceneData Variable
					if(@IPS_GetObjectIDByIdent("Scene".$ID."Data", $this->InstanceID) === false)
					{
						//SceneData
						$vid = IPS_CreateVariable(3 /* SceneData */);
						IPS_LogMessage("SceneModule", "Creating new SceneData Variable...");
					}
					else
					{
						$vid = IPS_GetObjectIDByIdent("Scene".$ID."Data", $this->InstanceID);
					}
					IPS_SetParent($vid, $this->InstanceID);
					IPS_SetName($vid, $data[$i]['name']."Data");
					IPS_SetIdent($vid, "Scene".$ID."Data");
					IPS_SetPosition($vid, $data[$i]['Position'] * 3 + $this->maxByKey($data, 'Position') + 1);
					IPS_SetHidden($vid, true);

					//Set Selector profile
					IPS_SetVariableProfileAssociation("USZS.Selector" . $this->InstanceID, ($i), $data[$i]['name'],"",-1);
				}

				//CreateSelector Variable
				{
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
					IPS_SetVariableCustomProfile($vid, "USZS.Selector" . $this->InstanceID);
					IPS_SetVariableCustomAction($vid, $svs);
				}

				//Create Selector event
				{
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
					IPS_SetEventScript($eid, "USZS_CallScene(". $this->InstanceID .", GetValue($vid));");
				}
			}

			//Get SetValue Script ID
			$svs = IPS_GetObjectIDByIdent("SetValueScript", $this->InstanceID);

			//Get Current Mode
			{
				$useDaySetModule = $this->ReadPropertyBoolean("ModeDaySet");
				$useTimeModule = $this->ReadPropertyBoolean("ModeTime");
			}
			
			// All the DaySet Module related things:
			if($useDaySetModule)
			{
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
									$currentName = @IPS_GetVariableProfile("USZS.Selector" . $this->InstanceID)['Associations'][GetValue($child)]['Name'];
									if($savedName != $currentName)
									{
										$assoc = $this->GetAssociationByName("USZS.Selector" . $this->InstanceID, $savedName);
										if($assoc !== false)
											SetValue($child, $assoc);
									}
								}
							}
						}
					}
				}

				// Get Sensor ID
				$sensorID = $this->ReadPropertyInteger("Sensor");

				//Create Sensor event
				{
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
						IPS_SetEventScript($eid, "USZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
						IPS_SetEventActive($eid, true);
						IPS_SetParent($eid, $eventsCat);
						IPS_SetName($eid, "Sensor.OnChange");
						IPS_SetIdent($eid, "SensorEvent");
					}
				}

				//Create "Steuerung" Dummy-Module 
				$this->checkDummy("Steuerung", IPS_GetParent($this->InstanceID), -1000);

				//Create Automatik for this instance
				{
					if(@IPS_GetObjectIDByIdent("Automatik", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID))) === false)
						$vid = IPS_CreateVariable(0);
					else
						$vid = IPS_GetObjectIDByIdent("Automatik", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID)));
					IPS_SetName($vid, "Automatik");
					IPS_SetParent($vid, $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID)));
					IPS_SetPosition($vid, -999);
					IPS_SetIdent($vid, "Automatik");
					IPS_SetVariableCustomAction($vid, $svs);
					IPS_SetVariableCustomProfile($vid, "Switch");
				}

				//Create Event for Automatik
				{
					if(@IPS_GetObjectIDByIdent("AutomatikEvent", $eventsCat) === false)
						$eid = IPS_CreateEvent(0);
					else
						$eid = IPS_GetObjectIDByIdent("AutomatikEvent", $eventsCat);
					IPS_SetEventTrigger($eid, 4, $vid);
					IPS_SetEventTriggerValue($eid, true);
					IPS_SetEventScript($eid, "USZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
					IPS_SetEventActive($eid, true);
					IPS_SetParent($eid, $eventsCat);
					IPS_SetName($eid, "Automatik.OnTrue");
					IPS_SetIdent($eid, "AutomatikEvent");
				}

				//Create Sperre for this instance
				{
					if(@IPS_GetObjectIDByIdent("Sperre", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID))) === false)
						$vid = IPS_CreateVariable(0);
					else
						$vid = IPS_GetObjectIDByIdent("Sperre", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID)));
					IPS_SetName($vid, "Sperre");
					IPS_SetParent($vid, $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID)));
					IPS_SetPosition($vid, -999);
					IPS_SetIdent($vid, "Sperre");
					IPS_SetVariableCustomAction($vid, $svs);
					IPS_SetVariableCustomProfile($vid, "Switch");
				}

				//Create Event for Sperre
				{
					if(@IPS_GetObjectIDByIdent("SperreEvent", $eventsCat) === false)
						$eid = IPS_CreateEvent(0);
					else
						$eid = IPS_GetObjectIDByIdent("SperreEvent", $eventsCat);
					IPS_SetEventTrigger($eid, 4, $vid);
					IPS_SetEventTriggerValue($eid, false);
					IPS_SetEventScript($eid, "USZS_CallScene(". $this->InstanceID .", ($sensorID *100));");
					IPS_SetEventActive($eid, true);
					IPS_SetParent($eid, $eventsCat);
					IPS_SetName($eid, "Sperre.OnFalse");
					IPS_SetIdent($eid, "SperreEvent");
				}

				//Create all the states (Morgen, Tag...)
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
						IPS_SetVariableCustomProfile($vid, "USZS.Selector" . $this->InstanceID);
	
						//Create Events for the States
						if(@IPS_GetObjectIDByIdent("SetEvent$i", $eventsCat) === false)
							$eid = IPS_CreateEvent(0);
						else
							$eid = IPS_GetObjectIDByIdent("SetEvent$i", $eventsCat);
						IPS_SetEventTrigger($eid, 1, $vid);
						IPS_SetEventScript($eid, "USZS_CallScene(" . $this->InstanceID . ", ($sensorID *100));");
						IPS_SetName($eid, "$state".".OnChange");
						IPS_SetParent($eid, $eventsCat);
						IPS_SetIdent($eid, "SetEvent$i");
						IPS_SetEventActive($eid, true);
					}
				}
			}
			else //Delete all excessive objects
			{
				//Delete Excessive Automation and DaySets
				$sensor = $this->ReadPropertyInteger("Sensor");
				if($sensor < 9999 || $useDaySetModule === false)
				{
					if(@IPS_GetObjectIDByIdent("SensorEvent", $eventsCat) !== false)
					{
						$autoVarEvent = IPS_GetObjectIDByIdent("SensorEvent", $eventsCat);
						IPS_DeleteEvent($autoVarEvent);
					}

					if(@IPS_GetObjectIDByIdent("Automatik", IPS_GetObjectIDByIdent("Steuerung", IPS_GetParent($this->InstanceID))) !== false)
					{
						$autoVar = IPS_GetObjectIDByIdent("Automatik", IPS_GetObjectIDByIdent("Steuerung", IPS_GetParent($this->InstanceID)));
						IPS_DeleteVariable($autoVar);
						$autoVarEvent = IPS_GetObjectIDByIdent("AutomatikEvent", $eventsCat);
						IPS_DeleteEvent($autoVarEvent);
					}

					if(@IPS_GetObjectIDByIdent("Sperre", IPS_GetObjectIDByIdent("Steuerung", IPS_GetParent($this->InstanceID))) !== false)
					{
						$sperreVar = IPS_GetObjectIDByIdent("Sperre", IPS_GetObjectIDByIdent("Steuerung", IPS_GetParent($this->InstanceID)));
						IPS_DeleteVariable($sperreVar);
						$sperreVarEvent = IPS_GetObjectIDByIdent("SperreEvent", $eventsCat);
						IPS_DeleteEvent($sperreVarEvent);
					}

					if(@IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID)) !== false)
					{
						$setIns = IPS_GetObjectIDByIdent("Set", IPS_GetParent($this->InstanceID));
						$this->Del($setIns);
					}

					foreach(IPS_GetChildrenIDs($eventsCat) as $child)
					{
						$ident = IPS_GetObject($child)['ObjectIdent'];
						if(strpos($ident, "SetEvent") !== false)
							IPS_DeleteEvent($child);
					}
				}
			}

			// All the Time Module related things:
			if($useTimeModule)
			{
				// Create Durchlauf Start/Stop Variable
				{
					if(@IPS_GetObjectIDByIdent("Status", $this->InstanceID) === false)
						$vid = IPS_CreateVariable(1 /* int */);
					else
						$vid = IPS_GetObjectIDByIdent("Status", $this->InstanceID);
					IPS_SetParent($vid, $this->InstanceID);
					IPS_SetName($vid, "Durchlauf");
					IPS_SetIdent($vid, "Status");
					IPS_SetPosition($vid, -100);
					IPS_SetVariableCustomProfile($vid, "SZS.StartStopButton");
					IPS_SetVariableCustomAction($vid,$svs);
				}
				
				// Create Durchlauf Start/Stop OnRefresh Event
				// and Timer Variables
				{
					//Create Timer Value Variables
					for($i = 0; $i < sizeof($data); $i++)
					{
						$ID = @$data[$i]['ID'];
						if(@IPS_GetObjectIDByIdent("Timer$ID", $this->InstanceID) === false)
						{
							$vid = IPS_CreateVariable(1 /* TimerValues */);
							SetValue($vid, 0);
						}
						else
							$vid = IPS_GetObjectIDByIdent("Timer$ID", $this->InstanceID);
						IPS_SetParent($vid, $this->InstanceID);
						IPS_SetName($vid, $data[$i]["name"] . " Timer");
						IPS_SetPosition($vid, $data[$i]["Position"] * 3 + 1);
						IPS_SetIdent($vid, "Timer$ID");
						IPS_SetVariableCustomProfile($vid, "SZS.Minutes");
						IPS_SetVariableCustomAction($vid, $svs);
						$this->EnableAction("Timer$ID");
					}

					$durchlaufID = IPS_GetObjectIDByIdent("Status", $this->InstanceID);

					if(@IPS_GetObjectIDByIdent("StatusEvent", $eventsCat) === false)
						$vid = IPS_CreateEvent(0 /* Ausgelößtes Event */);
					else
						$vid = IPS_GetObjectIDByIdent("StatusEvent", $eventsCat);
					IPS_SetParent($vid, $eventsCat);
					IPS_SetName($vid, "Durchlauf.OnRefresh");
					IPS_SetIdent($vid, "StatusEvent");
					IPS_SetEventTrigger($vid,0 /*bei aktuallisierung*/, $durchlaufID);
					IPS_SetEventScript($vid,'<?
					
					$association = IPS_GetVariableProfile("SZS.StartStopButton")["Associations"]; 
					if($association[0]["Name"] == "Stop")
					{	
						//Change Caption of the Button
						IPS_SetVariableProfileAssociation("SZS.StartStopButton", 1, "Start", "", -1);
						
						$targetIDs = IPS_GetObjectIDByIdent("Targets", '. IPS_GetParent($this->InstanceID) .');
						
						//set all targets to 0 or false
						foreach(IPS_GetChildrenIDs($targetIDs) as $TargetID) {
							//only allow links
							if(IPS_LinkExists($TargetID)) {
								$linkVariableID = IPS_GetLink($TargetID)[\'TargetID\'];
								if(IPS_VariableExists($linkVariableID)) {
									$type = IPS_GetVariable($linkVariableID)[\'VariableType\'];
									$id = $linkVariableID;
									
									$o = IPS_GetObject($id);
									$v = IPS_GetVariable($id);
									
									if($v["VariableCustomAction"] > 0)
										$actionID = $v["VariableCustomAction"];
									else
										$actionID = $v["VariableAction"];
									
									switch($type)
									{
										case(0):
											$value = false; break;
										case(1):
											$value = 0; break;
										case(2):
											$value = 0.0; break;
										case(3):
											$value = ""; break;				
									}

									//Skip this device if we do not have a proper id
										if($actionID < 10000)
										{
											SetValue($id, $value);
											continue;
										}
										
										
									if(IPS_InstanceExists($actionID)) {
										IPS_RequestAction($actionID, $o["ObjectIdent"], $value);
									}
									else if(IPS_ScriptExists($actionID))
									{
										echo IPS_RunScriptWaitEx($actionID, Array("VARIABLE" => $id, "VALUE" => $value, "SENDER" => "WebFront"));
									}
								}
							}
						}
						
						if(@IPS_GetObjectIDByIdent("TimerEvent", '. $this->InstanceID .') !== false)
						{
							$timerID = IPS_GetObjectIDByIdent("TimerEvent", '. $this->InstanceID .');
							IPS_DeleteEvent($timerID);
						}
					}
					else
					{	
						IPS_SetVariableProfileAssociation("SZS.StartStopButton", 1, "Stop", "", -1);
						
						//Timer
						if("'.$this->ReadPropertyBoolean("Loop").'" == "1")
						{
							$loop = 1;
						}
						else
						{
							$loop = 0;
						}
						
						$svid = IPS_GetObjectIDByIdent("Timer'. $data[0]['ID'] .'", '. $this->InstanceID .');
						$vid = IPS_CreateEvent(1 /* zyklisch */);
						IPS_SetParent($vid, '. $this->InstanceID .');
						IPS_SetName($vid, "Cycling Timer");
						IPS_SetIdent($vid, "TimerEvent");
						
						IPS_SetPosition($vid, ' . $data[0]["Position"] . ' * 3 + 2);
						IPS_SetEventCyclicTimeBounds($vid,time() + GetValue($svid)*60 + 1,time() + GetValue($svid)*60 + 1);
						IPS_SetEventActive($vid,true);
						IPS_SetEventScript($vid,"<?
						
					function sortByKey(\$arr, \$k)
					{
						\$d = array();
						foreach (\$arr as \$key => \$row)
						{
							\$d[\$key] = \$row[\$k];
							
						}
						array_multisort(\$arr, SORT_ASC, \$d);
						return \$arr;
					}

					function GetAssociationByName(\$profile, \$name)
					{
						\$associations = IPS_GetVariableProfile(\$profile)[\"Associations\"];
						foreach(\$associations as \$i => \$assoc)
						{
							if(\$assoc[\"Name\"] == \$name)
							{
								return \$i;
							}
						}
						return false;
					}

					IPS_SetEventActive($vid,false);
					IPS_Sleep(100);
					\$data = json_decode(IPS_GetConfiguration('. $this->InstanceID .'), true);
					\$data = json_decode(\$data[\"Names\"], true);
					\$data = sortByKey(\$data, \"Position\");
					\$dataTable = \$data;
					\$oldPos = (IPS_GetObject($vid)[\"ObjectPosition\"] - 2) / 3;
					\$nextSceneID = 0;
					\$nextEntry = array();
					foreach(\$data as \$i => \$entry)
					{
						if(\$entry[\"Position\"] == \$oldPos)
						{
							\$nextSceneID = @\$data[\$i + 1][\"ID\"];
							\$nextEntry = @\$data[\$i + 1];
						}
					}

					\$loop = json_decode(IPS_GetConfiguration('. $this->InstanceID .'), true)[\"Loop\"];

					if(@IPS_GetObjectIDByIdent(\"Scene\".\$nextSceneID, '. $this->InstanceID .') !== false)
					{
						\$data = wddx_deserialize(GetValue(IPS_GetObjectIDByIdent(\"Scene\".\$nextSceneID.\"Data\", '. $this->InstanceID .')));
						\$timerTime = GetValue(IPS_GetObjectIDByIdent(\"Timer\".\$nextSceneID, '. $this->InstanceID .'));
							if(\$data != NULL && \$timerTime != 0) {

								//Set Selector to current Scene
								if(@IPS_GetObjectIDByIdent(\"Scene\".\$nextSceneID, '. $this->InstanceID .') !== false)
								{
									\$selectVar = IPS_GetObjectIDByIdent(\"Selector\", IPS_GetParent('.$this->InstanceID.'));
									\$eventsCat = IPS_GetObjectIDByIdent(\"EventsCat\", '.$this->InstanceID.');
									\$selectEvent = IPS_GetObjectIDByIdent(\"SelectorOnChange\", \$eventsCat);
									IPS_SetEventActive(\$selectEvent, false);
									IPS_Sleep(100);
									\$sceneVar = IPS_GetObjectIDByIdent(\"Scene\".\$nextSceneID, '. $this->InstanceID .');
									\$sceneNum = GetAssociationByName(\"USZS.Selector\" . '.$this->InstanceID.', IPS_GetObject(\$sceneVar)[\"ObjectName\"]);
									SetValue(\$selectVar, \$sceneNum);
									IPS_Sleep(100);
									IPS_SetEventActive(\$selectEvent, true);
								}

								foreach(\$data as \$id => \$value) {
									if (IPS_VariableExists(\$id)){
										\$o = IPS_GetObject(\$id);
										\$v = IPS_GetVariable(\$id);
										if(\$v[\"VariableCustomAction\"] > 0)
											\$actionID = \$v[\"VariableCustomAction\"];
										else
											\$actionID = \$v[\"VariableAction\"];

										//Skip this device if we do not have a proper id
										if(\$actionID < 10000)
										{
											SetValue(\$id,\$value);
											continue;
										}
										
										if(IPS_InstanceExists(\$actionID)) {
											IPS_RequestAction(\$actionID, \$o[\"ObjectIdent\"], \$value);
										} else if(IPS_ScriptExists(\$actionID)) {
											echo IPS_RunScriptWaitEx(\$actionID, Array(\"VARIABLE\" => \$id, \"VALUE\" => \$value));
										}
									}
								}
							} else {
								echo \"No SceneData for this Scene\";
							}
					
						\$svid = IPS_GetObjectIDByIdent(\"Timer\". \$nextSceneID, '. $this->InstanceID .');
						IPS_SetPosition($vid, \$nextEntry[\"Position\"] * 3 + 2);
						IPS_SetEventCyclicTimeBounds($vid,time()+1+GetValue(\$svid)*60,time()+1+GetValue(\$svid)*60);
						IPS_Sleep(100);
						IPS_SetEventActive($vid,true);
					}
					else if(\$loop == 1)
					{
						\$firstSceneID = \$data[0][\"ID\"];
						\$data = wddx_deserialize(GetValue(IPS_GetObjectIDByIdent(\"Scene\" . \$firstSceneID . \"Data\", '. $this->InstanceID .')));
						\$timerTime = GetValue(IPS_GetObjectIDByIdent(\"Timer\" . \$firstSceneID , '. $this->InstanceID .'));
							
							if(\$data != NULL && \$timerTime != 0) {

								//Set Selector to current Scene
								if(@IPS_GetObjectIDByIdent(\"Scene\".\$firstSceneID ,'. $this->InstanceID .') !== false)
								{
									\$selectVar = IPS_GetObjectIDByIdent(\"Selector\", IPS_GetParent('.$this->InstanceID.'));
									\$eventsCat = IPS_GetObjectIDByIdent(\"EventsCat\", '.$this->InstanceID.');
									\$selectEvent = IPS_GetObjectIDByIdent(\"SelectorOnChange\", \$eventsCat);
									IPS_SetEventActive(\$selectEvent, false);
									IPS_Sleep(100);
									\$sceneVar = \IPS_GetObjectIDByIdent(\"Scene\".\$firstSceneID, '. $this->InstanceID .');
									\$sceneNum = GetAssociationByName(\"USZS.Selector\" . '.$this->InstanceID.', IPS_GetObject(\$sceneVar)[\"ObjectName\"]);
									SetValue(\$selectVar, \$sceneNum);
									IPS_Sleep(100);
									IPS_SetEventActive(\$selectEvent, true);
								}

								foreach(\$data as \$id => \$value) {
									if (IPS_VariableExists(\$id)){
										\$o = IPS_GetObject(\$id);
										\$v = IPS_GetVariable(\$id);
										if(\$v[\"VariableCustomAction\"] > 0)
											\$actionID = \$v[\"VariableCustomAction\"];
										else
											\$actionID = \$v[\"VariableAction\"];

										//Skip this device if we do not have a proper id
										if(\$actionID < 10000)
										{
											SetValue(\$id,\$value);
											continue;
										}
											
										if(IPS_InstanceExists(\$actionID)) {
											IPS_RequestAction(\$actionID, \$o[\"ObjectIdent\"], \$value);
										} else if(IPS_ScriptExists(\$actionID)) {
											echo IPS_RunScriptWaitEx(\$actionID, Array(\"VARIABLE\" => \$id, \"VALUE\" => \$value));
										}
									}
								}
							} else {
								echo \"No SceneData for this Scene\";
							}
						
						\$svid = IPS_GetObjectIDByIdent(\"Timer\" . \$firstSceneID ,'. $this->InstanceID .');
						IPS_SetPosition($vid, \$dataTable[0][\"Position\"] * 3 + 2);
						IPS_SetEventCyclicTimeBounds($vid,time()+1+GetValue(\$svid)*60,time()+1+GetValue(\$svid)*60);
						IPS_Sleep(100);
						IPS_SetEventActive($vid,true);
					}
					else
					{
						IPS_SetVariableProfileAssociation(\"SZS.StartStopButton\", 1, \"Start\", \"\", -1);
						
						\$targetIDs = IPS_GetObjectIDByIdent(\"Targets\", '. IPS_GetParent($this->InstanceID) .');
						
						//set all targets to 0 or false
						foreach(IPS_GetChildrenIDs(\$targetIDs) as \$TargetID) {
							//only allow links
							if(IPS_LinkExists(\$TargetID)) {
								\$linkVariableID = IPS_GetLink(\$TargetID)[\'TargetID\'];
								if(IPS_VariableExists(\$linkVariableID)) {
									\$type = IPS_GetVariable(\$linkVariableID)[\'VariableType\'];
									\$id = \$linkVariableID;
									
									\$o = IPS_GetObject(\$id);
									\$v = IPS_GetVariable(\$id);
									
									if(\$v[\"VariableCustomAction\"] > 0)
										\$actionID = \$v[\"VariableCustomAction\"];
									else
										\$actionID = \$v[\"VariableAction\"];
									
									//Skip this device if we do not have a proper id
										if(\$actionID < 10000)
										{
											SetValue(\$id, \$value);
											continue;
										}
										
									switch(\$type)
									{
										case(0):
											\$value = false; break;
										case(1):
											\$value = 0; break;
										case(2):
											\$value = 0.0; break;
										case(3):
											\$value = \"\"; break;				
									}
										
									if(IPS_InstanceExists(\$actionID)) {
										IPS_RequestAction(\$actionID, \$o[\"ObjectIdent\"], \$value);
									}
									else if(IPS_ScriptExists(\$actionID))
									{
										echo IPS_RunScriptWaitEx(\$actionID, Array(\"VARIABLE\" => \$id, \"VALUE\" => \$value));
									}
								}
							}
						}
						
						IPS_DeleteEvent($vid);
					}
					
					?>");
						
						function GetAssociationByName($profile, $name)
						{
							$associations = IPS_GetVariableProfile($profile)["Associations"];
							foreach($associations as $i => $assoc)
							{
								if($assoc["Name"] == $name)
								{
									return $i;
								}
							}
							return false;
						}

						//event run first scene
						$data = wddx_deserialize(GetValue(IPS_GetObjectIDByIdent("Scene". '. $data[0]['ID'] .' ."Data", '. $this->InstanceID .')));
						$timerTime = GetValue(IPS_GetObjectIDByIdent("Timer'. $data[0]['ID'] .'", '. $this->InstanceID .'));
							if($data != NULL && $timerTime != 0) {

								//Set Selector to current Scene
								if(@IPS_GetObjectIDByIdent("Scene". '. $data[0]['ID'] .', '. $this->InstanceID .') !== false)
								{
									$selectVar = IPS_GetObjectIDByIdent("Selector", IPS_GetParent('.$this->InstanceID.'));
									$eventsCat = IPS_GetObjectIDByIdent("EventsCat", '.$this->InstanceID.');
									$selectEvent = IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat);
									IPS_SetEventActive($selectEvent, false);
									IPS_Sleep(100);
									$sceneVar = IPS_GetObjectIDByIdent("Scene". '. $data[0]['ID'] .', '. $this->InstanceID .');
									$sceneNum = GetAssociationByName("USZS.Selector" . '.$this->InstanceID.', IPS_GetObject($sceneVar)["ObjectName"]);
									SetValue($selectVar, $sceneNum);
									IPS_Sleep(100);
									IPS_SetEventActive($selectEvent, true);
								}

								foreach($data as $id => $value) {
									if (IPS_VariableExists($id)){
										$o = IPS_GetObject($id);
										$v = IPS_GetVariable($id);
										if($v["VariableCustomAction"] > 0)
											$actionID = $v["VariableCustomAction"];
										else
											$actionID = $v["VariableAction"];

										//Skip this device if we do not have a proper id
										if($actionID < 10000)
										{
											SetValue($id,$value);
											continue;
										}
										
										if(IPS_InstanceExists($actionID)) {
											IPS_RequestAction($actionID, $o["ObjectIdent"], $value);
										} else if(IPS_ScriptExists($actionID)) {
											echo IPS_RunScriptWaitEx($actionID, Array("VARIABLE" => $id, "VALUE" => $value));
										}
									}
								}
							} else {
								echo "No SceneData for this Scene";
							}
					}
					?>');
					IPS_SetEventActive($vid, true);
				}
			}
			else //Delete all excessive objects
			{
				foreach(IPS_GetChildrenIDs($this->InstanceID) as $child)
				{
					$ident = IPS_GetObject($child)['ObjectIdent'];
					if(strpos($ident, "Timer") !== false || strpos($ident, "Status") !== false)
						$this->Del($child);
				}
				if(@IPS_GetObjectIDbyIdent("StatusEvent", $eventsCat) !== false)
					IPS_DeleteEvent(IPS_GetObjectIDbyIdent("StatusEvent", $eventsCat));
			}

			//Delete excessive Scences
			$ChildrenIDs = IPS_GetChildrenIDs($this->InstanceID);
			foreach($ChildrenIDs as $child)
			{
				$ident = IPS_GetObject($child)['ObjectIdent'];
				if(strpos($ident, "Scene") !== false || (strpos($ident, "Timer") !== false && strpos($ident, "Event") === false))
				{
					$entryExists = false;
					foreach($data as $j => $entry)
					{
						$excessiveID = str_replace("Scene", "", $ident);
						$excessiveID = str_replace("Data", "", $excessiveID);
						$excessiveID = str_replace("Timer", "", $excessiveID);
						if($excessiveID == $entry['ID'])
						{
							$entryExists = true;
						}
					}
					if($entryExists == false)
					{
						//copy values of older version of the module to the new one (shouldn't occur at all)
						if($excessiveID < 9999)
						{
							IPS_LogMessage("SceneModule", "copy values of an older version of the module to the new one");
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
	}

	public function RequestAction($Ident, $Value) {

		//Get Info of current mode (DaySet, Time)
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
			$assoc = IPS_GetVariableProfile("USZS.Selector" . $this->InstanceID)['Associations'][$ActualSceneNumber];
			$sceneVar = IPS_GetObjectIDByName($assoc['Name'], $this->InstanceID);
			$actualIdent = IPS_GetObject($sceneVar)['ObjectIdent'];
			$this->CallValues($actualIdent."Sensor");
		}
		else
		{
			if($SceneNumber < 10000) //sender = selector
			{
				$assoc = IPS_GetVariableProfile("USZS.Selector" . $this->InstanceID)['Associations'][$SceneNumber];
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

		IPS_LogMessage("SceneModule.SaveValues", "Saving new Scene Data Values...");
		IPS_LogMessage("SceneModule.SaveValues", "Targets from ". IPS_GetName(IPS_GetParent($targetIDs)) ."/". IPS_GetName($targetIDs) ." ($targetIDs) are being used)");
		

		//We want to save all Linked Values
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
			IPS_LogMessage("SceneModule.SaveValues", "couldn't access backup file: " . $e->getMessage());
		}
	}

	private function CallValues($SceneIdent) {

		$actualIdent = str_replace("Sensor", "", $SceneIdent);
		$selectValue = str_replace("Scene", "", $actualIdent);
		$sceneDataID = IPS_GetObjectIDByIdent($actualIdent."Data", $this->InstanceID);
		$dataStr = GetValue($sceneDataID);
		$data = wddx_deserialize($dataStr);
		if($data != NULL && strlen($dataStr) > 3) {
			//write into backup file after calling a scene
			try {
				$content = json_decode(@file_get_contents($this->docsFile), true);
				$content[$sceneDataID] = $dataStr;
				@file_put_contents($this->docsFile, json_encode($content));
			} catch (Exception $e) { 
				IPS_LogMessage("SceneModule.CallValues", "couldn't access backup file: " . $e->getMessage());
			}

			if(strpos($SceneIdent, "Sensor") !== false)
			{
				if(@IPS_GetObjectIDByIdent("Automatik", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID))) !== false)
				{
					$automatikID = IPS_GetObjectIDByIdent("Sperre", $this->searchObjectByName("Automatik", IPS_GetParent($this->InstanceID)));
					$auto = GetValue($automatikID);
				}

				if(@IPS_GetObjectIDByIdent("Sperre", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID))) !== false)
				{
					$SperreID = IPS_GetObjectIDByIdent("Sperre", $this->searchObjectByName("Steuerung", IPS_GetParent($this->InstanceID)));
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
				$sceneNum = $this->GetAssociationByName("USZS.Selector" . $this->InstanceID, IPS_GetObject($sceneVar)['ObjectName']);
				SetValue($selectVar, $sceneNum);
				IPS_Sleep(100);
				IPS_SetEventActive($selectEvent, true);

				IPS_LogMessage("SceneModule.CallValues", "Calling Values for Scene '".IPS_GetName($sceneVar)."'");
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
					if(isset($content))
					{
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

							//Set Selector to current Scene anyways
							{
								$selectVar = IPS_GetObjectIDByIdent("Selector", IPS_GetParent($this->InstanceID));
								$eventsCat = IPS_GetObjectIDByIdent("EventsCat", $this->InstanceID);
								$selectEvent = IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat);
								IPS_SetEventActive($selectEvent, false);
								IPS_Sleep(100);
								$sceneVar = IPS_GetObjectIDByIdent($actualIdent, $this->InstanceID);
								$sceneNum = $this->GetAssociationByName("USZS.Selector" . $this->InstanceID, IPS_GetObject($sceneVar)['ObjectName']);
								SetValue($selectVar, $sceneNum);
								IPS_Sleep(100);
								IPS_SetEventActive($selectEvent, true);
							}
						}
					}
					else //if there was never any targets
					{
						echo "No SceneData for this Scene";

						//Set Selector to current Scene anyways
						{
							$selectVar = IPS_GetObjectIDByIdent("Selector", IPS_GetParent($this->InstanceID));
							$eventsCat = IPS_GetObjectIDByIdent("EventsCat", $this->InstanceID);
							$selectEvent = IPS_GetObjectIDByIdent("SelectorOnChange", $eventsCat);
							IPS_SetEventActive($selectEvent, false);
							IPS_Sleep(100);
							$sceneVar = IPS_GetObjectIDByIdent($actualIdent, $this->InstanceID);
							$sceneNum = $this->GetAssociationByName("USZS.Selector" . $this->InstanceID, IPS_GetObject($sceneVar)['ObjectName']);
							SetValue($selectVar, $sceneNum);
							IPS_Sleep(100);
							IPS_SetEventActive($selectEvent, true);
						}
					}
				} catch (Exception $e) { 
					IPS_LogMessage("SceneModule.CallValues", "couldn't access backup file when SceneData was empty: " . $e->getMessage());
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

	//Helpers
	public function easyCreateVariable ($type = 1, $name = "Variable", $position = "", $index = 0, $defaultValue = null) {

            if ($position == "") {

                $position = $this->InstanceID;

            } 

            $newVariable = IPS_CreateVariable($type);
            IPS_SetName($newVariable, $name);
            IPS_SetParent($newVariable, $position);
            IPS_SetPosition($newVariable, $index);

            if ($defaultValue != null) {

                SetValue($newVariable, $defaultValue);

            }

            return $newVariable;

        }

    public function checkVar ($var, $type = 1, $profile = false , $position = "", $index = 0, $defaultValue = null) {

            if ($this->searchObjectByName($var) == 0) {

                $nVar = $this->easyCreateVariable($type, $var ,$position, $index, $defaultValue);

                if ($type == 0 && $profile == true) {

                    $this->addSwitch($nVar);

                }

                if ($type == 1 && $profile == true) {

                    $this->addTime($nVar);

                }

                return $nVar;

            } else {

                return $this->searchObjectByName($var);

            }

        }

	public function getModuleGuidByName ($name = "Dummy Module") {

            $allModules = IPS_GetModuleList();
            $GUID = ""; //init

            foreach ($allModules as $module) {

                if (IPS_GetModule($module)['ModuleName'] == $name) {

                    $GUID = $module;
                    break;

                }

            }

            return $GUID;

        }

        public function checkDummy ($name, $parent = "", $index = 0) {

            if ($this->searchObjectByName($name) == 0) {

                $targets = $this->createDummy($name);
                
                if ($parent != "") {

                	IPS_SetParent($targets, $parent);

                }
                IPS_SetPosition($targets, $index);
                //$this->hide($targets);

            }

        }

        public function createDummy ($name) {

            $units = IPS_CreateInstance($this->getModuleGuidByName());
            IPS_SetName($units, $name);
            IPS_SetParent($units, $this->InstanceID);

            return $units;

        }	

    public function searchObjectByName ($name, $searchIn = null) {

            if ($searchIn == null) {

                $searchIn = $this->InstanceID;

            }

            $childs = IPS_GetChildrenIDs($searchIn);

            $returnId = 0;

            foreach ($childs as $child) {

                $childObject = IPS_GetObject($child);

                if ($childObject['ObjectName'] == $name) {

                    $returnId = $childObject['ObjectID'];

                }

            }

            return $returnId;

        }

}
?>
