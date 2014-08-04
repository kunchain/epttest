<?php

class Application_Service_Evaluation {
	
	public function getAllDistributions($parameters)
    {

        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array("DATE_FORMAT(distribution_date,'%d-%b-%Y')", 'distribution_code', 's.shipment_code' ,'d.status');
        $orderColumns = array('distribution_date', 'distribution_code', 's.shipment_code' ,'d.status');

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'distribution_id';


        /*
         * Paging
         */
        $sLimit = "";
        if (isset($parameters['iDisplayStart']) && $parameters['iDisplayLength'] != '-1') {
            $sOffset = $parameters['iDisplayStart'];
            $sLimit = $parameters['iDisplayLength'];
        }

        /*
         * Ordering
         */
        $sOrder = "";
        if (isset($parameters['iSortCol_0'])) {
            $sOrder = "";
            for ($i = 0; $i < intval($parameters['iSortingCols']); $i++) {
                if ($parameters['bSortable_' . intval($parameters['iSortCol_' . $i])] == "true") {
                    $sOrder .= $orderColumns[intval($parameters['iSortCol_' . $i])] . "
				 	" . ($parameters['sSortDir_' . $i]) . ", ";
                }
            }

            $sOrder = substr_replace($sOrder, "", -2);
        }

        /*
         * Filtering
         * NOTE this does not match the built-in DataTables filtering which does it
         * word by word on any field. It's possible to do here, but concerned about efficiency
         * on very large tables, and MySQL's regex functionality is very limited
         */
        $sWhere = "";
        if (isset($parameters['sSearch']) && $parameters['sSearch'] != "") {
            $searchArray = explode(" ", $parameters['sSearch']);
            $sWhereSub = "";
            foreach ($searchArray as $search) {
                if ($sWhereSub == "") {
                    $sWhereSub .= "(";
                } else {
                    $sWhereSub .= " AND (";
                }
                $colSize = count($aColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if($aColumns[$i] == "" || $aColumns[$i] == null){
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($aColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }


        /*
         * SQL queries
         * Get data to display
         */
		
	$dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();

        $sQuery = $dbAdapter->select()->from(array('d' => 'distributions'))
				->joinLeft(array('s'=>'shipment'),'s.distribution_id=d.distribution_id',array('shipments' => new Zend_Db_Expr("GROUP_CONCAT(DISTINCT s.shipment_code SEPARATOR ', ')")))
				->where("d.status='shipped'")
				->group('d.distribution_id');

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        //die($sQuery);

        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $dbAdapter->select()->from('distributions', new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"))->where("status='shipped'");
        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        
        $shipmentDb = new Application_Model_DbTable_Shipments();

        foreach ($rResult as $aRow) {
            
            $shipmentResults = $shipmentDb->getPendingShipmentsByDistribution($aRow['distribution_id']);
            
            $row = array();
	    $row['DT_RowId']="dist".$aRow['distribution_id'];
            $row[] = Pt_Commons_General::humanDateFormat($aRow['distribution_date']);
            $row[] = $aRow['distribution_code'];
            $row[] = $aRow['shipments'];
            $row[] = ucwords($aRow['status']);
            $row[] = '<a class="btn btn-primary btn-xs" href="javascript:void(0);" onclick="getShipments(\''.($aRow['distribution_id']).'\')"><span><i class="icon-search"></i> View</span></a>';	    
            
            

            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }
	
	public function getShipments($distributionId){
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'))
					->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
					->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id',array('participant_count' => new Zend_Db_Expr('count("participant_id")'), 'reported_count'=> new Zend_Db_Expr("SUM(shipment_test_date <> '')"), 'number_passed'=> new Zend_Db_Expr("SUM(final_result = 1)")))
					->join(array('sl'=>'scheme_list'),'sl.scheme_id=s.scheme_type')
					->joinLeft(array('rr'=>'r_results'),'sp.final_result=rr.result_id')
					->where("s.distribution_id = ?",$distributionId)
					->group('s.shipment_id');
			  
	    return $db->fetchAll($sql);
	}
	
	public function getShipmentToEvaluate($shipmentId,$reEvaluate = false){
	    $db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'))
							->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
							->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id')
							->join(array('sl'=>'scheme_list'),'sl.scheme_id=s.scheme_type')
							->join(array('p'=>'participant'),'p.participant_id=sp.participant_id')
							->where("s.shipment_id = ?",$shipmentId)
							->where("substring(sp.evaluation_status,4,1) != '0'");
	    $shipmentResult = $db->fetchAll($sql);
		
		$schemeService = new Application_Service_Schemes();
		
		if($shipmentResult[0]['scheme_type'] == 'eid'){
			$counter = 0;
			$maxScore = 0;
			foreach($shipmentResult as $shipment){
				$createdOnUser=explode(" ",$shipment['created_on_user']);
				if(trim($createdOnUser[0]) != "" && $createdOnUser[0] != null && trim($createdOnUser[0]) !="0000-00-00"){
					
					$createdOn = new Zend_Date($createdOnUser[0], Zend_Date::ISO_8601);
				}else{
					$datearray = array('year' => 1970,'month' => 1,'day' => 01);
					$createdOn = new Zend_Date($datearray);
				}
				
				$lastDate = new Zend_Date($shipment['lastdate_response'], Zend_Date::ISO_8601);
				if($createdOn->isEarlier($lastDate)){
					$results = $schemeService->getEidSamples($shipmentId,$shipment['participant_id']);
					$totalScore = 0;
					$maxScore = 0;
					$mandatoryResult = "";
					$scoreResult = "";
					$failureReason = "";
					foreach($results as $result){
						
						// matching reported and reference results
						if(isset($result['reported_result']) && $result['reported_result'] !=null){						
							if($result['reference_result'] == $result['reported_result']){
								$totalScore += $result['sample_score'];
							}else{
								if($result['sample_score'] > 0){
									$failureReason[] = "Control/Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
								}
							}		
						}
						$maxScore  += $result['sample_score'];
						
						// checking if mandatory fields were entered and were entered right
						if($result['mandatory'] == 1){
							if((!isset($result['reported_result']) || $result['reported_result'] == "" || $result['reported_result'] == null)){
								$mandatoryResult = 'Fail';
								$failureReason[]= "Mandatory Control/Sample <strong>".$result['sample_label']."</strong> was not reported";
							}
							else if(($result['reference_result'] != $result['reported_result'])){
								$mandatoryResult = 'Fail';
								$failureReason[]= "Mandatory Control/Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
							}
						}
					}
				
					// checking if total score and maximum scores are the same
					if($totalScore != $maxScore){
						$scoreResult = 'Fail';
						$failureReason[]= "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$maxScore</strong>)";
					}else{
						$scoreResult = 'Pass';
					}
					
					// if any of the results have failed, then the final result is fail
					if($scoreResult == 'Fail' || $mandatoryResult == 'Fail'){
						$finalResult = 2;
					}else{
						$finalResult = 1;
					}
					$shipmentResult[$counter]['shipment_score'] = $totalScore;
					$shipmentResult[$counter]['max_score'] = $maxScore;
					$shipmentResult[$counter]['final_result'] = $finalResult;
					
					
					$fRes = $db->fetchCol($db->select()->from('r_results',array('result_name'))->where('result_id = '.$finalResult));
					
					$shipmentResult[$counter]['display_result'] = $fRes[0];				
					$shipmentResult[$counter]['failure_reason'] = $failureReason = ($failureReason != "" ? implode(",",$failureReason) : "");
					// let us update the total score in DB
					$db->update('shipment_participant_map',array('shipment_score' => $totalScore,'final_result'=>$finalResult, 'failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
					$counter++;
				}else{
					$failureReason = "Response was submitted after the last response date.";
					$db->update('shipment_participant_map',array('failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
				}
			}
			$db->update('shipment',array('max_score' => $maxScore), "shipment_id = ".$shipmentId);
		}
		else if($shipmentResult[0]['scheme_type'] == 'dbs'){
			$counter = 0;
			$maxScore = 0;
			foreach($shipmentResult as $shipment){
				$createdOnUser=explode(" ",$shipment['created_on_user']);
				if(trim($createdOnUser[0]) != "" && $createdOnUser[0] != null && trim($createdOnUser[0]) !="0000-00-00"){
					
					$createdOn = new Zend_Date($createdOnUser[0], Zend_Date::ISO_8601);
				}else{
					$datearray = array('year' => 1970,'month' => 1,'day' => 01);
					$createdOn = new Zend_Date($datearray);
				}
				
				$lastDate = new Zend_Date($shipment['lastdate_response'], Zend_Date::ISO_8601);
				if($createdOn->isEarlier($lastDate)){
					
					$results = $schemeService->getDbsSamples($shipmentId,$shipment['participant_id']);
					$totalScore = 0;
					$maxScore = 0;
					$mandatoryResult = "";
					$lotResult = "";
					$testKit1 = "";
					$testKit2 = "";
					$testKit3 = "";
					$testKitRepeatResult = "";
					$testKitExpiryResult = "";
					$lotResult = "";
					$scoreResult = "";
					$failureReason = "";
					
					$attributes = json_decode($shipment['attributes'],true);
					
					foreach($results as $result){
						
						// matching reported and reference results
						if(isset($result['reported_result']) && $result['reported_result'] !=null){
							if($result['reference_result'] == $result['reported_result']){
								$totalScore += $result['sample_score'];
							}else{
								if($result['sample_score'] > 0){
									$failureReason[] = "Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
								}
							}
						}
						$maxScore  += $result['sample_score'];
						
						// checking if mandatory fields were entered and were entered right
						if($result['mandatory'] == 1){
							if((!isset($result['reported_result']) || $result['reported_result'] == "" || $result['reported_result'] == null)){
								$mandatoryResult = 'Fail';
								$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was not reported";
							}
							//else if(($result['reference_result'] != $result['reported_result'])){
							//	$mandatoryResult = 'Fail';
							//	$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
							//}
						}
						
						
					}
					
					// checking if all LOT details were entered
					if(!isset($results[0]['lot_no_1']) || $results[0]['lot_no_1'] == "" || $results[0]['lot_no_1'] == null){
						$lotResult = 'Fail';
						$failureReason[]= "<strong>Lot No. 1</strong> was not reported";
					}
					if(!isset($results[0]['lot_no_2']) || $results[0]['lot_no_2'] == "" || $results[0]['lot_no_2'] == null){
						$lotResult = 'Fail';
						$failureReason[]= "<strong>Lot No. 2</strong> was not reported";
					}
					if(!isset($results[0]['lot_no_3']) || $results[0]['lot_no_3'] == "" || $results[0]['lot_no_3'] == null){
						$lotResult = 'Fail';
						$failureReason[]= "<strong>Lot No. 3</strong> was not reported";
					}
					
					// checking test kit expiry dates
				
					$testedOn = new Zend_Date($results[0]['shipment_test_date'], Zend_Date::ISO_8601);
					$testDate = $testedOn->toString('dd-MMM-YYYY');
					$expDate1="";
					if(trim(strtotime($results[0]['exp_date_1']))!=""){
						$expDate1 = new Zend_Date($results[0]['exp_date_1'], Zend_Date::ISO_8601);
					}
					$expDate2="";
					if(trim(strtotime($results[0]['exp_date_2']))!=""){
						$expDate2 = new Zend_Date($results[0]['exp_date_2'], Zend_Date::ISO_8601);
					}
					
					$expDate3="";
					if(trim(strtotime($results[0]['exp_date_3']))!=""){
						$expDate3 = new Zend_Date($results[0]['exp_date_3'], Zend_Date::ISO_8601);
					}
					
	
					$testKitName = $db->fetchCol($db->select()->from('r_dbs_eia','eia_name')->where("eia_id = '".$results[0]['eia_1']. "'"));
					$testKit1 = $testKitName[0];
					$testKit2="";
					if(trim($results[0]['eia_2'])!=0){
						$testKitName = $db->fetchCol($db->select()->from('r_dbs_eia','eia_name')->where("eia_id = '".$results[0]['eia_2']. "'"));
						$testKit2 = $testKitName[0];
					}
					
					$testKit3="";
					if(trim($results[0]['eia_3'])!=0){
						$testKitName = $db->fetchCol($db->select()->from('r_dbs_eia','eia_name')->where("eia_id = '".$results[0]['eia_3']. "'"));
						$testKit3 = $testKitName[0];
					}
					if($expDate1!=""){
						if($testedOn->isLater($expDate1)){
							$difference = $testedOn->sub($expDate1);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							
							$testKitExpiryResult = 'Fail';
							$failureReason[]= "EIA 1 (<strong>".$testKit1."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}
					$testedOn = new Zend_Date($results[0]['shipment_test_date'], Zend_Date::ISO_8601);
					$testDate = $testedOn->toString('dd-MMM-YYYY');
					if($expDate2!=""){
						if($testedOn->isLater($expDate2)){
							$difference = $testedOn->sub($expDate2);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							
							$testKitExpiryResult = 'Fail';
							$failureReason[]= "EIA 2 (<strong>".$testKit2."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}
					
					$testedOn = new Zend_Date($results[0]['shipment_test_date'], Zend_Date::ISO_8601);
					$testDate = $testedOn->toString('dd-MMM-YYYY');
					if($expDate3!=""){
						if($testedOn->isLater($expDate3)){
							$difference = $testedOn->sub($expDate3);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							
							$testKitExpiryResult = 'Fail';
							$failureReason[]= "EIA 3 (<strong>".$testKit3."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}
					//checking if testkits were repeated
					if(($testKit1 == $testKit2) && ($testKit2 == $testKit3)){
						//$testKitRepeatResult = 'Fail';
						$failureReason[]= "<strong>$testKit1</strong> repeated for all three EIA";					
					}else{
						if(($testKit1 == $testKit2) && $testKit1 !="" && $testKit2 != ""){
							//$testKitRepeatResult = 'Fail';
							$failureReason[]= "<strong>$testKit1</strong> repeated as EIA 1 and EIA 2";
						}
						if(($testKit2 == $testKit3) && $testKit2 !="" && $testKit3 != ""){
							//$testKitRepeatResult = 'Fail';
							$failureReason[]= "<strong>$testKit2</strong> repeated as EIA 2 and EIA 3";
						}
						if(($testKit1 == $testKit3) && $testKit1 !="" && $testKit3 != ""){
							//$testKitRepeatResult = 'Fail';
							$failureReason[]= "<strong>$testKit1</strong> repeated as EIA 1 and EIA 3";
						}					
					}
					
					// checking if total score and maximum scores are the same
					if($totalScore != $maxScore){
						$scoreResult = 'Fail';
						$failureReason[]= "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$maxScore</strong>)";
					}else{
						$scoreResult = 'Pass';
					}
					
					// if any of the results have failed, then the final result is fail
					if($scoreResult == 'Fail' || $mandatoryResult == 'Fail' || $lotResult == 'Fail' || $testKitExpiryResult == 'Fail'){
						$finalResult = 2;
					}else{
						$finalResult = 1;
					}
					$shipmentResult[$counter]['shipment_score'] = $totalScore;
					$shipmentResult[$counter]['max_score'] = $maxScore;
					
					$fRes = $db->fetchCol($db->select()->from('r_results',array('result_name'))->where('result_id = '.$finalResult));
					
					$shipmentResult[$counter]['display_result'] = $fRes[0];
					$shipmentResult[$counter]['failure_reason'] = $failureReason = ($failureReason != "" ? implode(",",$failureReason) : "");
					
					// let us update the total score in DB
					$nofOfRowsUpdated = $db->update('shipment_participant_map',array('shipment_score' => $totalScore,'final_result'=>$finalResult, 'failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
					$counter++;
				}else{
					$failureReason = "Response was submitted after the last response date.";
					$db->update('shipment_participant_map',array('failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
				}
			}
			$db->update('shipment',array('max_score' => $maxScore), "shipment_id = ".$shipmentId);
			
		}
		else if($shipmentResult[0]['scheme_type'] == 'dts'){
			
			$counter = 0;
			$maxScore = 0;
			
			foreach($shipmentResult as $shipment){
				$createdOnUser=explode(" ",$shipment['created_on_user']);
				if(trim($createdOnUser[0]) != "" && $createdOnUser[0] != null && trim($createdOnUser[0]) !="0000-00-00"){
					
					$createdOn = new Zend_Date($createdOnUser[0], Zend_Date::ISO_8601);
				}else{
					$datearray = array('year' => 1970,'month' => 1,'day' => 01);
					$createdOn = new Zend_Date($datearray);
				}
					
				$results = $schemeService->getDtsSamples($shipmentId,$shipment['participant_id']);
				$totalScore = 0;
				$maxScore = 0;
				$mandatoryResult = "";
				$lotResult = "";
				$testKit1 = "";
				$testKit2 = "";
				$testKit3 = "";
				$testKitRepeatResult = "";
				$testKitExpiryResult = "";
				$lotResult = "";
				$scoreResult = "";
				$failureReason = "";
				$algoResult = "";
				$lastDateResult = "";
				
				$attributes = json_decode($shipment['attributes'],true);
				
				$sampleRehydrationDate = new Zend_Date($attributes['sample_rehydration_date'], Zend_Date::ISO_8601);
				$testedOn = new Zend_Date($results[0]['shipment_test_date'], Zend_Date::ISO_8601);
				
				
				// Testing should be done within 24 hours of rehydration.
				$diff = $testedOn->sub($sampleRehydrationDate)->toValue();
				$days = ceil($diff/60/60/24) +1;				
				if($days > 1){
				   $failureReason[] = "Testing should be done within 24 hours of rehydration.";
				}
				
				
				//Response was submitted after the last response date.
				$lastDate = new Zend_Date($shipment['lastdate_response'], Zend_Date::ISO_8601);				
				if(!$createdOn->isEarlier($lastDate)){
					$lastDateResult = 'Fail';
					$failureReason[] = "Response was submitted after the last response date.";
				}
				
				
				//Please make sure your result is approved by supervisor
				if($shipment['supervisor_approval'] == "" || $shipment['supervisor_approval'] == NULL || strtolower($shipment['supervisor_approval']) == 'no'){
					$failureReason[] = "Please make sure your result is approved by supervisor.";
				}
				
				
				
				//$serialCorrectResponses = array('NXX','PNN','PPX','PNP');				
				//$parallelCorrectResponses = array('PPX','PNP','PNN','NNX','NPN','NPP');
				
				
				
				foreach($results as $result){
					
					$testedOn = new Zend_Date($results[0]['shipment_test_date'], Zend_Date::ISO_8601);
					
					$r1 = $r2 = $r3 = '';
					if($result['test_result_1'] == 1){
						$r1 = 'R';
					} else if($result['test_result_1'] == 2){
						$r1 = 'NR';
					} else if($result['test_result_1'] == 3){
						$r1 = 'I';
					}else{
						$r1 = '-';
					}
					if($result['test_result_2'] == 1){
						$r2 = 'R';
					} else if($result['test_result_2'] == 2){
						$r2 = 'NR';
					} else if($result['test_result_2'] == 3){
						$r2 = 'I';
					}else{
						$r2 = '-';
					}
					if($result['test_result_3'] == 1){
						$r3 = 'R';
					} else if($result['test_result_3'] == 2){
						$r3 = 'NR';
					} else if($result['test_result_3'] == 3){
						$r3 = 'I';
					}else{
						$r3 = '-';
					}
					
					$algoString = "Wrongly reported in the pattern : <strong>". $r1."</strong> <strong>".$r2."</strong> <strong>".$r3."</strong>";

					if($attributes['algorithm'] == 'serial'){
						
						if($r1 == 'NR'){
							if(($r2 == '-') && ($r3 == '-')){
								$algoResult = 'Pass';	
							}else{
								$algoResult = 'Fail';
								$failureReason[]= "For <strong>".$result['sample_label']."</strong> Serial Algorithm was not followed ($algoString)";								
							}							
						}else if($r1 == 'R' && $r2 == 'NR' && $r3 == 'NR'){
							$algoResult = 'Pass';
						}else if($r1 == 'R' && $r2 == 'R'){
							if(($r3 == '-')){
								$algoResult = 'Pass';	
							}else{
								$algoResult = 'Fail';
								$failureReason[]= "For <strong>".$result['sample_label']."</strong> Serial Algorithm was not followed ($algoString)";					
							}
						}else if($r1 == 'R' && $r2 == 'NR' && $r3 == 'R'){
							$algoResult = 'Pass';
						}else{
							$algoResult = 'Fail';
							$failureReason[]= "For <strong>".$result['sample_label']."</strong> Serial Algorithm was not followed ($algoString)";	
						}
						
					} else if($attributes['algorithm'] == 'parallel'){
						
						if($r1 == 'R' && $r2 == 'R'){
							if(($r3 == '-')){
								$algoResult = 'Pass';	
							}else{
								
								$algoResult = 'Fail';
								$failureReason[]= "For <strong>".$result['sample_label']."</strong> Parallel Algorithm was not followed ($algoString)";									
							}
						}else if($r1 == 'R' && $r2 == 'NR' && $r3 == 'R'){
							$algoResult = 'Pass';
						}else if($r1 == 'R' && $r2 == 'NR' && $r3 == 'NR'){
							$algoResult = 'Pass';
						}else if($r1 == 'NR' && $r2 == 'NR'){
							if(($r3 == '-')){
								$algoResult = 'Pass';	
							}else{
								$algoResult = 'Fail';
								$failureReason[]= "For <strong>".$result['sample_label']."</strong> Parallel Algorithm was not followed ($algoString)";	
							}
						}else if($r1 == 'NR' && $r2 == 'R' && $r3 == 'NR'){
							$algoResult = 'Pass';
						}else if($r1 == 'NR' && $r2 == 'R' && $r3 == 'R'){
							$algoResult = 'Pass';
						}else{
							$algoResult = 'Fail';
							$failureReason[]= "For <strong>".$result['sample_label']."</strong> Parallel Algorithm was not followed ($algoString)";	
						}
					}
					
					// matching reported and reference results
					if(isset($result['reported_result']) && $result['reported_result'] !=null){
						if($result['reference_result'] == $result['reported_result']){
							$totalScore += $result['sample_score'];
						}else{
							if($result['sample_score'] > 0){
								$failureReason[] = "Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
							}
						}
					}
					$maxScore  += $result['sample_score'];
					
					// checking if mandatory fields were entered and were entered right
					if($result['mandatory'] == 1){
						if((!isset($result['reported_result']) || $result['reported_result'] == "" || $result['reported_result'] == null)){
							$mandatoryResult = 'Fail';
							$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was not reported";
						}
						//else if(($result['reference_result'] != $result['reported_result'])){
						//	$mandatoryResult = 'Fail';
						//	$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
						//}
					}
					
					
				}
				
					
				// checking test kit expiry dates
			
				$testDate = $testedOn->toString('dd-MMM-YYYY');
				$expDate1="";
				//die($results[0]['exp_date_1']);
				if(trim(strtotime($results[0]['exp_date_1']))!=""){
					$expDate1 = new Zend_Date($results[0]['exp_date_1'], Zend_Date::ISO_8601);
				}
				$expDate2="";
				if(trim(strtotime($results[0]['exp_date_2']))!=""){
					$expDate2 = new Zend_Date($results[0]['exp_date_2'], Zend_Date::ISO_8601);
				}
				$expDate3="";
				if(trim(strtotime($results[0]['exp_date_3']))!=""){
					$expDate3 = new Zend_Date($results[0]['exp_date_3'], Zend_Date::ISO_8601);
				}
				
				
				$testKit1 = "";
				$testKitName = $db->fetchCol($db->select()->from('r_testkitname_dts','TestKit_Name')->where("TestKitName_ID = '".$results[0]['test_kit_name_1']. "'"));
				if(isset($testKitName[0])){
					$testKit1 = $testKitName[0];
				}
				
				$testKit2="";
				if(trim($results[0]['test_kit_name_2'])!=""){
					$testKitName = $db->fetchCol($db->select()->from('r_testkitname_dts','TestKit_Name')->where("TestKitName_ID = '".$results[0]['test_kit_name_2']. "'"));
					if(isset($testKitName[0])){
						$testKit2 = $testKitName[0];
					}
				}
				$testKit3="";
				if(trim($results[0]['test_kit_name_3'])!=""){
					$testKitName = $db->fetchCol($db->select()->from('r_testkitname_dts','TestKit_Name')->where("TestKitName_ID = '".$results[0]['test_kit_name_3']. "'"));
					if(isset($testKitName[0])){
						$testKit3 = $testKitName[0];
					}
				}
				
				if($testKit1 != ""){
					if($expDate1!=""){
						if($testedOn->isLater($expDate1)){
							$difference = $testedOn->sub($expDate1);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							if(isset($result['test_result_1']) && $result['test_result_1'] != "" && $result['test_result_1'] != null){
								$testKitExpiryResult = 'Fail';
							}
							$failureReason[]= "Test Kit 1 (<strong>".$testKit1."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}else{
						if(isset($result['test_result_1']) && $result['test_result_1'] != "" && $result['test_result_1'] != null){
							$testKitExpiryResult = 'Fail';
						}
						$failureReason[]= "Test Kit 1 (<strong>".$testKit1."</strong>) reported without expiry date";
					}
				}
				$testDate = $testedOn->toString('dd-MMM-YYYY');
				
				if($testKit2 != ""){
					if($expDate2!=""){
						if($testedOn->isLater($expDate2)){
							$difference = $testedOn->sub($expDate2);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							if(isset($result['test_result_2']) && $result['test_result_2'] != "" && $result['test_result_2'] != null){
								$testKitExpiryResult = 'Fail';
							}
							$failureReason[]= "Test Kit 2 (<strong>".$testKit2."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}else{
						if(isset($result['test_result_2']) && $result['test_result_2'] != "" && $result['test_result_2'] != null){
							$testKitExpiryResult = 'Fail';
						}
						$failureReason[]= "Test Kit 2 (<strong>".$testKit2."</strong>) reported without expiry date";
					}
				}
			
			
				$testDate = $testedOn->toString('dd-MMM-YYYY');
				
				if($testKit3 != ""){
					if($expDate3!=""){
						if($testedOn->isLater($expDate3)){
							$difference = $testedOn->sub($expDate3);
							
							$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
							$measure->convertTo(Zend_Measure_Time::DAY);
							if(isset($result['test_result_3']) && $result['test_result_3'] != "" && $result['test_result_3'] != null){
								$testKitExpiryResult = 'Fail';
							}
							$failureReason[]= "Test Kit 3 (<strong>".$testKit3."</strong>) expired ".round($measure->getValue()). " days before the test date ".$testDate;
						}
					}else{
						if(isset($result['test_result_3']) && $result['test_result_3'] != "" && $result['test_result_3'] != null){
							$testKitExpiryResult = 'Fail';
						}
						$failureReason[]= "Test Kit 3 (<strong>".$testKit3."</strong>) reported without expiry date";
					}
				}
				//checking if testkits were repeated
				if(($testKit1 == "") && ($testKit2 == "") && ($testKit3 == "")){
					$failureReason[]= "No Test Kit reported";					
				}
				else if(($testKit1 == "")){
					$failureReason[]= "Test Kit 1 not reported";
				}
				else if(($testKit1 != "") && ($testKit2 != "") && ($testKit3 != "") && ($testKit1 == $testKit2) && ($testKit2 == $testKit3)){
					//$testKitRepeatResult = 'Fail';
					$failureReason[]= "<strong>$testKit1</strong> repeated for all three Test Kits";					
				}else{
					if(($testKit1 != "") && ($testKit2 != "") && ($testKit1 == $testKit2) && $testKit1 !="" && $testKit2 != ""){
						//$testKitRepeatResult = 'Fail';
						$failureReason[]= "<strong>$testKit1</strong> repeated as Test Kit 1 and Test Kit 2";
					}
					if(($testKit2 != "") && ($testKit3 != "") && ($testKit2 == $testKit3) && $testKit2 !="" && $testKit3 != ""){
						//$testKitRepeatResult = 'Fail';
						$failureReason[]= "<strong>$testKit2</strong> repeated as Test Kit 2 and Test Kit 3";
					}
					if(($testKit1 != "") && ($testKit3 != "") && ($testKit1 == $testKit3) && $testKit1 !="" && $testKit3 != ""){
						//$testKitRepeatResult = 'Fail';
						$failureReason[]= "<strong>$testKit1</strong> repeated as Test Kit 1 and Test Kit 3";
					}					
				}
				

				// checking if all LOT details were entered
				if($testKit1 != "" && (!isset($results[0]['lot_no_1']) || $results[0]['lot_no_1'] == "" || $results[0]['lot_no_1'] == null)){
					$lotResult = 'Fail';
					$failureReason[]= "<strong>Lot No. 1</strong> was not reported";
				}
				if($testKit2 != "" && (!isset($results[0]['lot_no_2']) || $results[0]['lot_no_2'] == "" || $results[0]['lot_no_2'] == null)){
					$lotResult = 'Fail';
					$failureReason[]= "<strong>Lot No. 2</strong> was not reported";
				}
				if($testKit3 != "" && (!isset($results[0]['lot_no_3']) || $results[0]['lot_no_3'] == "" || $results[0]['lot_no_3'] == null)){
					$lotResult = 'Fail';
					$failureReason[]= "<strong>Lot No. 3</strong> was not reported";
				}					
			
				// checking if total score and maximum scores are the same
				if($totalScore != $maxScore){
					$scoreResult = 'Fail';
					$failureReason[]= "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$maxScore</strong>)";
				}else{
					$scoreResult = 'Pass';
				}				
			

				// if we are excluding this result, then let us not give pass/fail				
				if($shipment['is_excluded'] == 'yes'){
					$finalResult = '';
					$shipmentResult[$counter]['shipment_score'] = $totalScore = 0;
					$shipmentResult[$counter]['display_result'] = '';
					$shipmentResult[$counter]['failure_reason'] = $failureReason = 'Excluded from Evaluation';
				}else{
					// if any of the results have failed, then the final result is fail
					if($algoResult== 'Fail' || $scoreResult == 'Fail' || $lastDateResult == 'Fail' || $mandatoryResult == 'Fail' || $lotResult == 'Fail' || $testKitExpiryResult == 'Fail'){
						$finalResult = 2;
					}else{
						$finalResult = 1;
					}					
					$shipmentResult[$counter]['shipment_score'] = $totalScore;
					
					$fRes = $db->fetchCol($db->select()->from('r_results',array('result_name'))->where('result_id = '.$finalResult));
				
					$shipmentResult[$counter]['display_result'] = $fRes[0];
					$shipmentResult[$counter]['failure_reason'] = $failureReason = ($failureReason != "" ? implode(",",$failureReason) : "");
				}
				
				$shipmentResult[$counter]['max_score'] = $maxScore;
				
				// let us update the total score in DB
				$nofOfRowsUpdated = $db->update('shipment_participant_map',array('shipment_score' => $totalScore,'final_result'=>$finalResult, 'failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
				$counter++;
				
			}
			$db->update('shipment',array('max_score' => $maxScore), "shipment_id = ".$shipmentId);
		} else if($shipmentResult[0]['scheme_type'] == 'vl'){
			$counter = 0;
			$maxScore = 0;
			$vlRange = $schemeService->getVlRange($shipmentId);
			if($reEvaluate || $vlRange == null || $vlRange == "" || count($vlRange) == 0){
				$schemeService->setVlRange($shipmentId);
				$vlRange = $schemeService->getVlRange($shipmentId);
			}
			
			foreach($shipmentResult as $shipment){
				$createdOnUser=explode(" ",$shipment['created_on_user']);
				if(trim($createdOnUser[0]) != "" && $createdOnUser[0] != null && trim($createdOnUser[0]) !="0000-00-00"){
					
					$createdOn = new Zend_Date($createdOnUser[0], Zend_Date::ISO_8601);
				}else{
					$datearray = array('year' => 1970,'month' => 1,'day' => 01);
					$createdOn = new Zend_Date($datearray);
				}
				
				$lastDate = new Zend_Date($shipment['lastdate_response'], Zend_Date::ISO_8601);
				//Zend_Debug::dump($createdOn->isEarlier($lastDate));die;
				if($createdOn->isEarlier($lastDate)){
					
					$results = $schemeService->getVlSamples($shipmentId,$shipment['participant_id']);
					$totalScore = 0;
					$maxScore = 0;
					$mandatoryResult = "";
					$scoreResult = "";
					$failureReason = "";
					
					$attributes = json_decode($shipment['attributes'],true);
					
					foreach($results as $result){
						$responseAssay = json_decode($result['attributes'],true);
						$responseAssay = $responseAssay['vl_assay'];
						if(isset($vlRange[$responseAssay])){
							// matching reported and low/high limits
							if(isset($result['reported_viral_load']) && $result['reported_viral_load'] !=null){
								if($vlRange[$responseAssay][$result['sample_id']]['low'] <= $result['reported_viral_load'] && $vlRange[$responseAssay][$result['sample_id']]['high'] >= $result['reported_viral_load']){
									$totalScore += $result['sample_score'];
								}else{
									if($result['sample_score'] > 0){
										$failureReason[] = "Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
									}
								}
							}
						}else{
							$totalScore = "N/A";
						}
						
						$maxScore  += $result['sample_score'];
						
						// checking if mandatory fields were entered and were entered right
						if($result['mandatory'] == 1){
							if((!isset($result['reported_viral_load']) || $result['reported_viral_load'] == "" || $result['reported_viral_load'] == null)){
								$mandatoryResult = 'Fail';
								$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was not reported";
							}
							//else if(($result['reported_viral_load'] != $result['reported_viral_load'])){
							//	$mandatoryResult = 'Fail';
							//	$failureReason[]= "Mandatory Sample <strong>".$result['sample_label']."</strong> was reported wrongly";
							//}
						}
					}
					
					// checking if total score and maximum scores are the same
					if($totalScore == 'N/A'){
						$failureReason[] = "Could not determine score. Not enough responses found in the chosen VL Assay.";
						$scoreResult = 'Fail';
					}
					else if($totalScore != $maxScore){
						$scoreResult = 'Fail';
						$failureReason[]= "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$maxScore</strong>)";
					}else{
						$scoreResult = 'Pass';
					}				
					
					
					// if any of the results have failed, then the final result is fail
					if($scoreResult == 'Fail' || $mandatoryResult == 'Fail'){
						$finalResult = 2;
					}else{
						$finalResult = 1;
					}
					$shipmentResult[$counter]['shipment_score'] = $totalScore;
					$shipmentResult[$counter]['max_score'] = $maxScore;
					
					$fRes = $db->fetchCol($db->select()->from('r_results',array('result_name'))->where('result_id = '.$finalResult));
					
					$shipmentResult[$counter]['display_result'] = $fRes[0];
					$shipmentResult[$counter]['failure_reason'] = $failureReason = ($failureReason != "" ? implode(",",$failureReason) : "");
					
					
					
					// let us update the total score in DB
					if($totalScore == 'N/A'){
						$totalScore = 0;
					}
					$nofOfRowsUpdated = $db->update('shipment_participant_map',array('shipment_score' => $totalScore,'final_result'=>$finalResult, 'failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
					$counter++;
				}else{
					$failureReason = "Response was submitted after the last response date.";
					
					$db->update('shipment_participant_map',array('failure_reason' => $failureReason), "map_id = ".$shipment['map_id']);
				}
			}
			$db->update('shipment',array('max_score' => $maxScore), "shipment_id = ".$shipmentId);			
		}
		
		return $shipmentResult;
	}
		

	public function editEvaluation($shipmentId,$participantId,$scheme){
            $participantService = new Application_Service_Participants();
			$schemeService = new Application_Service_Schemes();
			$shipmentService = new Application_Service_Shipments();
			
			
            $participantData = $participantService->getParticipantDetails($participantId);
			$shipmentData = $schemeService->getShipmentData($shipmentId,$participantId);
			
			if($scheme == 'eid'){
				$possibleResults = $schemeService->getPossibleResults('eid');
				$evalComments = $schemeService->getSchemeEvaluationComments('eid');
				$results = $schemeService->getEidSamples($shipmentId,$participantId);								
			} else if($scheme == 'vl'){
				$possibleResults = "";
				$evalComments = $schemeService->getSchemeEvaluationComments('vl');
				$results = $schemeService->getVlSamples($shipmentId,$participantId);				
			} else if($scheme == 'dts'){
				$possibleResults = $schemeService->getPossibleResults('dts');
				$evalComments = $schemeService->getSchemeEvaluationComments('dts');
				$results = $schemeService->getDtsSamples($shipmentId,$participantId);								
			} else if($scheme == 'dbs'){
				$possibleResults = $schemeService->getPossibleResults('dbs');
				$evalComments = $schemeService->getSchemeEvaluationComments('dbs');
				$results = $schemeService->getDbsSamples($shipmentId,$participantId);								
			}
				

			$db = Zend_Db_Table_Abstract::getDefaultAdapter();
			$sql = $db->select()->from(array('s'=>'shipment'))
							->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
							->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id', array('fullscore'=>new Zend_Db_Expr("SUM(if(s.max_score = sp.shipment_score, 1, 0))")))
							->join(array('p'=>'participant'),'p.participant_id=sp.participant_id')
							->where("sp.shipment_id = ?",$shipmentId)
							->where("substring(sp.evaluation_status,4,1) != '0'");
			$shipmentOverall = $db->fetchAll($sql);
			
			
			$noOfParticipants = count($shipmentOverall);
			$numScoredFull = $shipmentOverall[0]['fullscore'];
			$maxScore = $shipmentOverall[0]['max_score'];
			
			$controlRes = array();
			$sampleRes = array();
			if(isset($results) && count($results)>0){
				foreach($results as $res){
					if($res['control'] == 1){
						$controlRes[] = $res;
					}else{
						$sampleRes[] = $res;
					}
				}
			}
			
			

			return array('participant'=>$participantData,
			             'shipment' => $shipmentData ,
						 'possibleResults' => $possibleResults,
						 'totalParticipants' => $noOfParticipants,
						 'fullScorers' => $numScoredFull,
						 'maxScore' => $maxScore,
						 'evalComments' => $evalComments,
						 'controlResults' => $controlRes, 
						 'results' => $sampleRes );
	
	}
	
	public function viewEvaluation($shipmentId,$participantId,$scheme){


            $participantService = new Application_Service_Participants();
			$schemeService = new Application_Service_Schemes();
			$shipmentService = new Application_Service_Shipments();
			
			
            $participantData = $participantService->getParticipantDetails($participantId);
			$shipmentData = $schemeService->getShipmentData($shipmentId,$participantId);
			
			
			
			if($scheme == 'eid'){
				$possibleResults = $schemeService->getPossibleResults('eid');
				$evalComments = $schemeService->getSchemeEvaluationComments('eid');
				$results = $schemeService->getEidSamples($shipmentId,$participantId);								
			} else if($scheme == 'vl'){
				$possibleResults = "";
				$evalComments = $schemeService->getSchemeEvaluationComments('vl');
				$results = $schemeService->getVlSamples($shipmentId,$participantId);				
			} else if($scheme == 'dts'){
				$possibleResults = $schemeService->getPossibleResults('dts');
				$evalComments = $schemeService->getSchemeEvaluationComments('dts');
				$results = $schemeService->getDtsSamples($shipmentId,$participantId);								
			}else if($scheme == 'dbs'){
				$possibleResults = $schemeService->getPossibleResults('dbs');
				$evalComments = $schemeService->getSchemeEvaluationComments('dbs');
				$results = $schemeService->getDtsSamples($shipmentId,$participantId);								
			}
				
			
			$controlRes = array();
			$sampleRes = array();
			
			if(isset($results) && count($results)>0){
				foreach($results as $res){
					if($res['control'] == 1){
						$controlRes[] = $res;
					}else{
						$sampleRes[] = $res;
					}
				}
			}			

				
			$db = Zend_Db_Table_Abstract::getDefaultAdapter();
			$sql = $db->select()->from(array('s'=>'shipment'))
							->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
							->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id', array('fullscore'=>new Zend_Db_Expr("(if(s.max_score = sp.shipment_score, 1, 0))")))
							->join(array('p'=>'participant'),'p.participant_id=sp.participant_id')
							->where("sp.shipment_id = ?",$shipmentId)
							->where("substring(sp.evaluation_status,4,1) != '0'")
							->group('sp.map_id');
			$shipmentOverall = $db->fetchAll($sql);
			
			
			$noOfParticipants = count($shipmentOverall);
			$numScoredFull = 0;
			foreach($shipmentOverall as $shipment){
				$numScoredFull += $shipment['fullscore'];	
			}
			
			$maxScore = $shipmentOverall[0]['max_score'];
			
			
			

			return array('participant'=>$participantData,
			             'shipment' => $shipmentData ,
						 'possibleResults' => $possibleResults,
						 'totalParticipants' => $noOfParticipants,
						 'fullScorers' => $numScoredFull,
						 'maxScore' => $maxScore,
						 'evalComments' => $evalComments,
						 'controlResults' => $controlRes,
						 'results' => $sampleRes );
	
	}
	
	public function updateShipmentResults($params){
		 $db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$authNameSpace = new Zend_Session_Namespace('administrators');
		$admin = $authNameSpace->primary_email;		 
		 $size = count($params['sampleId']);
		 if($params['scheme'] == 'eid'){
			for($i=0;$i<$size;$i++){
			   $db->update('response_result_eid',array('reported_result' => $params['reported'][$i], 'updated_by'=>$admin , 'updated_on' => new Zend_Db_Expr('now()')), "shipment_map_id = ".$params['smid']. " AND sample_id = ".$params['sampleId'][$i]);
			}
		 }
		 else if($params['scheme'] == 'dts'){
			for($i=0;$i<$size;$i++){
			   $db->update('response_result_dts',array(
				'test_kit_name_1' => $params['test_kit_name_1'],
				'lot_no_1' => $params['lot_no_1'],
				'exp_date_1' => Pt_Commons_General::dateFormat($params['exp_date_1']),
				'test_result_1' => $params['test_result_1'][$i],
				'test_kit_name_2' => $params['test_kit_name_2'],
				'lot_no_2' => $params['lot_no_2'],
				'exp_date_2' => Pt_Commons_General::dateFormat($params['exp_date_2']),
				'test_result_2' => $params['test_result_2'][$i],
				'test_kit_name_3' => $params['test_kit_name_3'],
				'lot_no_3' => $params['lot_no_3'],
				'exp_date_3' => Pt_Commons_General::dateFormat($params['exp_date_3']),
				'test_result_3' => $params['test_result_3'][$i],
				'reported_result' => $params['reported_result'][$i],
				'updated_by'=>$admin ,
				'updated_on' => new Zend_Db_Expr('now()')), "shipment_map_id = ".$params['smid']. " AND sample_id = ".$params['sampleId'][$i]);
			}
		 }
		 else if($params['scheme'] == 'vl'){
			
			for($i=0;$i<$size;$i++){
								
			   $db->update('response_result_vl',array(
				'reported_viral_load' => $params['reported'][$i],
				'updated_by'=>$admin ,
				'updated_on' => new Zend_Db_Expr('now()')), "shipment_map_id = ".$params['smid']. " AND sample_id = ".$params['sampleId'][$i]);
			}
		 }
		 else if($params['scheme'] == 'dbs'){
			for($i=0;$i<$size;$i++){
			   $db->update('response_result_dbs',array(
				'eia_1'=>$params['eia_1'],
				'lot_no_1'=>$params['lot_no_1'],
				'exp_date_1'=>Pt_Commons_General::dateFormat($params['exp_date_1']),
				'od_1'=>$params['od_1'][$i],
				'cutoff_1'=>$params['cutoff_1'][$i],
				'eia_2'=>$params['eia_2'],
				'lot_no_2'=>$params['lot_no_2'],
				'exp_date_2'=>Pt_Commons_General::dateFormat($params['exp_date_2']),
				'od_2'=>$params['od_2'][$i],
				'cutoff_2'=>$params['cutoff_2'][$i],
				'eia_3'=>$params['eia_3'],
				'lot_no_3'=>$params['lot_no_3'],
				'exp_date_3'=>Pt_Commons_General::dateFormat($params['exp_date_3']),
				'od_3'=>$params['od_3'][$i],
				'cutoff_3'=>$params['cutoff_3'][$i],
				'wb'=>$params['wb'],
				'wb_lot'=>$params['wb_lot'],
				'wb_exp_date'=>Pt_Commons_General::dateFormat($params['wb_exp_date']),
				'wb_160'=>$params['wb_160'][$i],
				'wb_120'=>$params['wb_120'][$i],
				'wb_66'=>$params['wb_66'][$i],
				'wb_55'=>$params['wb_55'][$i],
				'wb_51'=>$params['wb_51'][$i],
				'wb_41'=>$params['wb_41'][$i],
				'wb_31'=>$params['wb_31'][$i],
				'wb_24'=>$params['wb_24'][$i],
				'wb_17'=>$params['wb_17'][$i],                                    
				'reported_result'=>$params['reported_result'][$i],
				'updated_by'=>$admin ,
				'updated_on' => new Zend_Db_Expr('now()')), "shipment_map_id = ".$params['smid']. " AND sample_id = ".$params['sampleId'][$i]);
			}
		 }
		 
		$params['isFollowUp'] = (isset($params['isFollowUp']) && $params['isFollowUp'] !="" ) ? $params['isFollowUp'] : "no";

		$db->update('shipment_participant_map',array('evaluation_comment' => $params['comment'],'optional_eval_comment' => $params['optionalComments'],'is_followup' => $params['isFollowUp'],'is_excluded' => $params['isExcluded'], 'updated_by_admin'=>$admin , 'updated_on_admin' => new Zend_Db_Expr('now()')), "map_id = ".$params['smid']);
		
	}
	
	public function updateShipmentComment($shipmentId,$comment){
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$authNameSpace = new Zend_Session_Namespace('administrators');
		$admin = $authNameSpace->primary_email;		 
		$noOfRows = $db->update('shipment',array('shipment_comment' => $comment,'updated_by_admin'=>$admin , 'updated_on_admin' => new Zend_Db_Expr('now()')), "shipment_id = ".$shipmentId);
		if($noOfRows > 0){
			return "Comment updated";
		}else{
			return "Unable to update shipment comment. Please try again later.";
		}
		
	}
	
	public function getShipmentToEvaluateReports($shipmentId,$reEvaluate = false){
	    $db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'))
				->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
				->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id')
				->join(array('sl'=>'scheme_list'),'sl.scheme_id=s.scheme_type')
				->join(array('p'=>'participant'),'p.participant_id=sp.participant_id')
				->joinLeft(array('res'=>'r_results'),'res.result_id=sp.final_result')
				->where("s.shipment_id = ?",$shipmentId)
				->where("substring(sp.evaluation_status,4,1) != '0'");
				
		$shipmentResult = $db->fetchAll($sql);
		return $shipmentResult;
	}
	
	public function getEvaluateReportsInPdf($shipmentId){
		$responseResult="";
		$vlCalculation="";
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'),array('s.shipment_id','s.shipment_code','s.scheme_type','s.shipment_date','s.lastdate_response','s.max_score'))
				->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id',array('d.distribution_id','d.distribution_code','d.distribution_date'))
				->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id',array('sp.map_id','sp.participant_id','sp.shipment_test_date','sp.shipment_receipt_date','sp.shipment_test_report_date','sp.final_result','sp.failure_reason','sp.shipment_score','sp.final_result','sp.attributes','sp.is_followup','sp.is_excluded'))
				->join(array('sl'=>'scheme_list'),'sl.scheme_id=s.scheme_type',array('sl.scheme_id','sl.scheme_name'))
				->join(array('p'=>'participant'),'p.participant_id=sp.participant_id',array('p.unique_identifier','p.first_name','p.last_name','p.status'))
				->joinLeft(array('res'=>'r_results'),'res.result_id=sp.final_result',array('result_name'))
				->where("s.shipment_id = ?",$shipmentId)
				//->where("(sp.final_result IS NOT NULL OR is_excluded='yes')")
				//->where("sp.final_result!=''")
				->where("substring(sp.evaluation_status,4,1) != '0'");
		//error_log($sql);die;
		$shipmentResult = $db->fetchAll($sql);
		$i=0;
		foreach($shipmentResult as $res){
			if($res['scheme_type']=='dbs'){
				$sQuery=$db->select()->from(array('resdbs'=>'response_result_dbs'),array('resdbs.shipment_map_id','resdbs.sample_id','resdbs.reported_result','responseDate'=>'resdbs.created_on'))
					->join(array('respr'=>'r_possibleresult'),'respr.id=resdbs.reported_result',array('labResult'=>'respr.response'))
					->join(array('sp'=>'shipment_participant_map'),'sp.map_id=resdbs.shipment_map_id',array('sp.shipment_id','sp.participant_id'))
					->join(array('refdbs'=>'reference_result_dbs'),'refdbs.shipment_id=sp.shipment_id and refdbs.sample_id=resdbs.sample_id',array('refdbs.reference_result','refdbs.sample_label'))
					->join(array('refpr'=>'r_possibleresult'),'refpr.id=refdbs.reference_result',array('referenceResult'=>'refpr.response'))
					->where("resdbs.shipment_map_id = ?",$res['map_id']);
					
				$shipmentResult[$i]['responseResult'] = $db->fetchAll($sQuery);
				
			}
			else if($res['scheme_type']=='dts'){
				
				$sQuery=$db->select()->from(array('resdts'=>'response_result_dts'),array('resdts.shipment_map_id','resdts.sample_id','resdts.reported_result','responseDate'=>'resdts.created_on'))
					->join(array('respr'=>'r_possibleresult'),'respr.id=resdts.reported_result',array('labResult'=>'respr.response'))
					->join(array('sp'=>'shipment_participant_map'),'sp.map_id=resdts.shipment_map_id',array('sp.shipment_id','sp.participant_id'))
					->join(array('refdts'=>'reference_result_dts'),'refdts.shipment_id=sp.shipment_id and refdts.sample_id=resdts.sample_id',array('refdts.reference_result','refdts.sample_label'))
					->join(array('refpr'=>'r_possibleresult'),'refpr.id=refdts.reference_result',array('referenceResult'=>'refpr.response'))
					->where("resdts.shipment_map_id = ?",$res['map_id']);
				//error_log($sQuery);
				$shipmentResult[$i]['responseResult'] = $db->fetchAll($sQuery);
			}
			else if($res['scheme_type']=='eid'){
				
				$sQuery=$db->select()->from(array('reseid'=>'response_result_eid'),array('reseid.shipment_map_id','reseid.sample_id','reseid.reported_result','responseDate'=>'reseid.created_on'))
					->join(array('respr'=>'r_possibleresult'),'respr.id=reseid.reported_result',array('labResult'=>'respr.response'))
					->join(array('sp'=>'shipment_participant_map'),'sp.map_id=reseid.shipment_map_id',array('sp.shipment_id','sp.participant_id'))
					->join(array('refeid'=>'reference_result_eid'),'refeid.shipment_id=sp.shipment_id and refeid.sample_id=reseid.sample_id',array('refeid.reference_result','refeid.sample_label'))
					->join(array('refpr'=>'r_possibleresult'),'refpr.id=refeid.reference_result',array('referenceResult'=>'refpr.response'))
					->where("reseid.shipment_map_id = ?",$res['map_id']);
				//error_log($sQuery);
				$shipmentResult[$i]['responseResult'] = $db->fetchAll($sQuery);
				
			}
			else if($res['scheme_type']=='vl'){
				$schemeService = new Application_Service_Schemes();
				$vlAssayResultSet = $schemeService->getVlAssay();
				$vlAssayList = array();
				foreach($vlAssayResultSet as $vlAssayRow){
					$vlAssayList[$vlAssayRow['id']] = $vlAssayRow['name'];
				}
				
				$vlRange = $schemeService->getVlRange($shipmentId);
				$results = $schemeService->getVlSamples($shipmentId,$res['participant_id']);
				
				$attributes = json_decode($res['attributes'],true);
				$counter = 0;
				$toReturn = array();
				foreach($results as $result){
					//$toReturn = array();
					$responseAssay = json_decode($result['attributes'],true);
					$toReturn[$counter]['vl_assay'] = $vlAssayList[$responseAssay['vl_assay']];
					$responseAssay = $responseAssay['vl_assay'];						
					
					$toReturn[$counter]['sample_label'] = $result['sample_label'];
					$toReturn[$counter]['shipment_map_id'] = $result['map_id'];
					$toReturn[$counter]['shipment_id'] = $result['shipment_id'];
					$toReturn[$counter]['responseDate'] = $result['responseDate'];
					$toReturn[$counter]['shipment_score'] = $result['shipment_score'];
					$toReturn[$counter]['max_score'] = $result['max_score'];
					$toReturn[$counter]['reported_viral_load'] = $result['reported_viral_load'];
					if(isset($vlRange[$responseAssay])){
						// matching reported and low/high limits
						if(isset($result['reported_viral_load']) && $result['reported_viral_load'] !=null){
							if($vlRange[$responseAssay][$result['sample_id']]['low'] <= $result['reported_viral_load'] && $vlRange[$responseAssay][$result['sample_id']]['high'] >= $result['reported_viral_load']){
								$grade = 'Acceptable';
							}else{
								$grade = 'Not Acceptable';
							}
						}
						
						if(isset($result['reported_viral_load']) && $result['reported_viral_load'] !=null){
							if($vlRange[$responseAssay][$result['sample_id']]['low'] <= $result['reported_viral_load'] && $vlRange[$responseAssay][$result['sample_id']]['high'] >= $result['reported_viral_load']){
								$grade = 'Acceptable';
							}else{
								if($result['sample_score'] > 0){
									$grade = 'Not Acceptable';
								}else{
									$grade = '-';
								}
							}
						}
						
						$toReturn[$counter]['low'] = $vlRange[$responseAssay][$result['sample_id']]['low'];
						$toReturn[$counter]['high'] = $vlRange[$responseAssay][$result['sample_id']]['high'];
						$toReturn[$counter]['sd'] = $vlRange[$responseAssay][$result['sample_id']]['sd'];
						$toReturn[$counter]['mean'] = $vlRange[$responseAssay][$result['sample_id']]['mean'];								
						
					}else{
						$toReturn[$counter]['low'] = 'Not Applicable';
						$toReturn[$counter]['high'] = 'Not Applicable';
						$toReturn[$counter]['sd'] = 'Not Applicable';
						$toReturn[$counter]['mean'] = 'Not Applicable';
						$grade = 'Not Applicable';
					}
					$toReturn[$counter]['grade'] = $grade;
					
					
					$counter++;
				}
				
				$shipmentResult[$i]['responseResult'] = $toReturn;							
				
				
				
				
			}
			
			$i++;
			$db->update('shipment_participant_map',array('report_generated'=>'yes'),"map_id=".$res['map_id']);
			$db->update('shipment',array('status'=>'evaluated'),"shipment_id=".$shipmentId);
		}
		if($res['scheme_type']=='vl'){
			$schemeService = new Application_Service_Schemes();
			$vlAssayResultSet = $schemeService->getVlAssay();
			foreach($vlAssayResultSet as $vlAssayRow){
				$vlCalRes=$db->fetchAll($db->select()->from(array('vlCal'=>'reference_vl_calculation'))
							->join(array('refVl'=>'reference_result_vl'),'refVl.shipment_id=vlCal.shipment_id and vlCal.sample_id=refVl.sample_id',array('refVl.sample_label'))
							->where("vlCal.shipment_id=?",$res['shipment_id'])->where("vlCal.vl_assay=?",$vlAssayRow['id']));
				
				if(count($vlCalRes)>0){
					$vlCalculation[$vlAssayRow['id']]=$vlCalRes;
					$vlCalculation[$vlAssayRow['id']]['vlAssay']=$vlAssayRow['name'];
				}
			}
		}
		
		   
		$result=array('shipment'=>$shipmentResult,'vlCalculation'=>$vlCalculation);
		
		
		return $result;
	}
	
	
	public function getSummaryReportsInPdf($shipmentId){
		$responseResult="";
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'),array('s.shipment_id','s.shipment_code','s.scheme_type','s.shipment_date','s.lastdate_response','s.max_score'))
				->join(array('sl'=>'scheme_list'),'sl.scheme_id=s.scheme_type',array('sl.scheme_name'))
				->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id',array('d.distribution_code'))
				->where("s.shipment_id = ?",$shipmentId);
		//error_log($sql);
		$shipmentResult = $db->fetchRow($sql);
		$i=0;
		if($shipmentResult!=""){
			$db->update('shipment',array('status' => 'evaluated'), "shipment_id = ".$shipmentId);
			if($shipmentResult['scheme_type']=='dbs'){
				$sql=$db->select()->from(array('refdbs'=>'reference_result_dbs'),array('refdbs.reference_result','refdbs.sample_label'))
					->join(array('refpr'=>'r_possibleresult'),'refpr.id=refdbs.reference_result',array('referenceResult'=>'refpr.response'))
					->where("refdbs.shipment_id = ?",$shipmentResult['shipment_id']);
				$sqlRes= $db->fetchAll($sql);
				
				$shipmentResult['referenceResult']=$sqlRes;
				
				$sQuery=$db->select()->from(array('spm'=>'shipment_participant_map'),array('spm.map_id','spm.shipment_id','spm.shipment_score','spm.attributes'))
				->join(array('p'=>'participant'),'p.participant_id=spm.participant_id',array('p.unique_identifier','p.first_name','p.last_name','p.status'))
				->joinLeft(array('res'=>'r_results'),'res.result_id=spm.final_result',array('result_name'))
				->where("spm.shipment_id = ?",$shipmentId)
				->where("substring(spm.evaluation_status,4,1) != '0'")
				->where("spm.final_result IS NOT NULL")
				->where("spm.final_result!=''")
				->group('spm.map_id');
				$sQueryRes= $db->fetchAll($sQuery);
				
				if(count($sQueryRes) > 0 ){
					
					$tQuery=$db->select()->from(array('refdbs'=>'reference_result_dbs'),array('refdbs.sample_id','refdbs.sample_label'))
						->join(array('resdbs'=>'response_result_dbs'),'resdbs.sample_id=refdbs.sample_id',array('correctRes' => new Zend_Db_Expr("SUM(CASE WHEN resdbs.reported_result=refdbs.reference_result THEN 1 ELSE 0 END)")))
						->join(array('spm'=>'shipment_participant_map'),'resdbs.shipment_map_id=spm.map_id and refdbs.shipment_id=spm.shipment_id',array())
						->where("spm.shipment_id = ?",$shipmentId)
						->where("spm.final_result IS NOT NULL")
						->where("spm.final_result!=''")
						->where("substring(spm.evaluation_status,4,1) != '0'")
						->group(array("refdbs.sample_id"));
					
					$shipmentResult['summaryResult'][]=$sQueryRes;
					$shipmentResult['summaryResult'][count($shipmentResult['summaryResult'])-1]['correctCount']=$db->fetchAll($tQuery);
					
					
					$rQuery=$db->select()->from(array('spm'=>'shipment_participant_map'),array('spm.map_id','spm.shipment_id'))
						->join(array('resdbs'=>'response_result_dbs'),'resdbs.shipment_map_id=spm.map_id',array('resdbs.eia_1','resdbs.eia_2','resdbs.eia_3','resdbs.wb'))
						->where("substring(spm.evaluation_status,4,1) != '0'")
						->where("spm.final_result IS NOT NULL")
						->where("spm.final_result!=''")
						->where("spm.shipment_id = ?",$shipmentId)
						->group('spm.map_id');
					
					$rQueryRes= $db->fetchAll($rQuery);
					$eiaEiaEiaWb='';
					$eiaEiaEia='';
					$eiaEiaWb='';
					$eiaEia='';
					$eiaWb='';
					$eia='';
					foreach($rQueryRes as $rVal){
						if($rVal['eia_1']!=0 && $rVal['eia_2']!=0 && $rVal['eia_3']!=0 && $rVal['wb']!=0){
							++$eiaEiaEiaWb;
						}elseif($rVal['eia_1']!=0 && $rVal['eia_2']!=0 && $rVal['eia_3']!=0){
							++$eiaEiaEia;
						}elseif($rVal['eia_1']!=0 && ($rVal['eia_2']!=0 || $rVal['eia_3']!=0) && $rVal['wb']!=0){
							++$eiaEiaWb;
						}elseif($rVal['eia_1']!=0 && ($rVal['eia_2']!=0 || $rVal['eia_3']!=0)){
							++$eiaEia;
						}elseif($rVal['eia_1']!=0 && $rVal['wb']!=0){
							++$eiaWb;
						}elseif($rVal['eia_1']!=0){
							++$eia;
						}
					}
					
					$shipmentResult['dbsPieChart']['EIA/EIA/EIA/WB']=$eiaEiaEiaWb;
					$shipmentResult['dbsPieChart']['EIA/EIA/EIA']=$eiaEiaEia;
					$shipmentResult['dbsPieChart']['EIA/EIA/WB']=$eiaEiaWb;
					$shipmentResult['dbsPieChart']['EIA/EIA']=$eiaEia;
					$shipmentResult['dbsPieChart']['EIA/WB']=$eiaWb;
					$shipmentResult['dbsPieChart']['EIA']=$eia;
					
				}
			}
			else if($shipmentResult['scheme_type']=='dts'){
				$sql=$db->select()->from(array('refdts'=>'reference_result_dts'),array('refdts.reference_result','refdts.sample_label'))
					->join(array('refpr'=>'r_possibleresult'),'refpr.id=refdts.reference_result',array('referenceResult'=>'refpr.response'))
					->where("refdts.shipment_id = ?",$shipmentResult['shipment_id']);
				$sqlRes= $db->fetchAll($sql);
				
				$shipmentResult['referenceResult']=$sqlRes;
				
				$sQuery=$db->select()->from(array('spm'=>'shipment_participant_map'),array('spm.map_id','spm.shipment_id','spm.shipment_score','spm.attributes'))
				->join(array('p'=>'participant'),'p.participant_id=spm.participant_id',array('p.unique_identifier','p.first_name','p.last_name','p.status'))
				->joinLeft(array('res'=>'r_results'),'res.result_id=spm.final_result',array('result_name'))
				->where("spm.shipment_id = ?",$shipmentId)
				->where("spm.final_result IS NOT NULL")
				->where("spm.final_result!=''")
				->where("substring(spm.evaluation_status,4,1) != '0'")
				->group('spm.map_id');
				$sQueryRes= $db->fetchAll($sQuery);
				//error_log($sQuery);
				if(count($sQueryRes) > 0 ){
					
					$tQuery=$db->select()->from(array('refdts'=>'reference_result_dts'),array('refdts.sample_id','refdts.sample_label'))
						->join(array('resdts'=>'response_result_dts'),'resdts.sample_id=refdts.sample_id',array('correctRes' => new Zend_Db_Expr("SUM(CASE WHEN resdts.reported_result=refdts.reference_result THEN 1 ELSE 0 END)")))
						->join(array('spm'=>'shipment_participant_map'),'resdts.shipment_map_id=spm.map_id and refdts.shipment_id=spm.shipment_id',array())
						->where("spm.shipment_id = ?",$shipmentId)
						->where("spm.final_result IS NOT NULL")
						->where("spm.final_result!=''")
						->where("substring(spm.evaluation_status,4,1) != '0'")
						->group(array("refdts.sample_id"));
					
					$shipmentResult['summaryResult'][]=$sQueryRes;
					$shipmentResult['summaryResult'][count($shipmentResult['summaryResult'])-1]['correctCount']=$db->fetchAll($tQuery);
					
					$kitNameRes=$db->fetchAll($db->select()->from('r_testkitname_dts'));
					
					$rQuery=$db->select()->from(array('spm'=>'shipment_participant_map'),array('spm.map_id','spm.shipment_id'))
						->join(array('resdts'=>'response_result_dts'),'resdts.shipment_map_id=spm.map_id',array('resdts.test_kit_name_1','resdts.test_kit_name_2','resdts.test_kit_name_3'))
						->where("spm.final_result IS NOT NULL")
						->where("spm.final_result!=''")
						->where("substring(spm.evaluation_status,4,1) != '0'")
						->where("spm.shipment_id = ?",$shipmentId)
						->group('spm.map_id');
					$rQueryRes= $db->fetchAll($rQuery);	
					$p=0;
					$kitName=array();
					foreach($kitNameRes as $res){
						$k=1;
						foreach($rQueryRes as $rVal){
							if($res['TestKitName_ID']==$rVal['test_kit_name_1']){
								$kitName[$p]['kit_name']=$res['TestKit_Name'];
								$kitName[$p]['count']=$k++;
							}
							if($res['TestKitName_ID']==$rVal['test_kit_name_2']){
								$kitName[$p]['kit_name']=$res['TestKit_Name'];
								$kitName[$p]['count']=$k++;
							}
							if($res['TestKitName_ID']==$rVal['test_kit_name_3']){
								$kitName[$p]['kit_name']=$res['TestKit_Name'];
								$kitName[$p]['count']=$k++;
							}
							
						}
						
						$p++;
					}
					$shipmentResult['pieChart']=$kitName;
					
				}
				
				$sql = $db->select()->from(array('p'=>'participant'))
									->join(array('spm'=>'shipment_participant_map'),'spm.participant_id=p.participant_id')
									->where("spm.shipment_id = ?",$shipmentId);
				
				
				$shipmentResult['participantScores'] = $db->fetchAll($sql);
			}
			else if($shipmentResult['scheme_type']=='eid'){
				$schemeService = new Application_Service_Schemes();
				
				$extractionAssay = $schemeService->getEidExtractionAssay();
				$detectionAssay = $schemeService->getEidDetectionAssay();
				
				foreach($extractionAssay as $extractionAssayVal){
					foreach($detectionAssay as $detectionAssayVal){
						$extId=$extractionAssayVal['id'];
						$detId=$detectionAssayVal['id'];
						
						$sQuery=$db->select()->from(array('spm'=>'shipment_participant_map'),array('spm.map_id','spm.shipment_id','spm.shipment_score','spm.attributes'))
						->join(array('refeid'=>'reference_result_eid'),'refeid.shipment_id=spm.shipment_id',array('refeid.sample_label'))
						->join(array('eidExtrac'=>'r_eid_extraction_assay'),"eidExtrac.id=$extId",array('eidExtracName'=>'eidExtrac.name'))
						->join(array('eidDetec'=>'r_eid_detection_assay'),"eidDetec.id=$detId",array('eidDetecName'=>'eidDetec.name'))
						->where("spm.shipment_id = ?",$shipmentId)
						->where("spm.attributes LIKE '%\"extraction_assay\":\"$extId\"%' ")
						->where("spm.attributes LIKE '%\"detection_assay\":\"$detId\"%' ")
						->where("spm.final_result IS NOT NULL")
						->where("spm.final_result!=''")
						->where("substring(spm.evaluation_status,4,1) != '0'")
						->group('spm.map_id');
						//echo "<br/>";
						$sQueryRes= $db->fetchAll($sQuery);
						
						if(count($sQueryRes) > 0 ){
							$tQuery=$db->select()->from(array('refeid'=>'reference_result_eid'),array('refeid.sample_id','refeid.sample_label'))
									->join(array('reseid'=>'response_result_eid'),'reseid.sample_id=refeid.sample_id',
									       array('correctRes' => new Zend_Db_Expr("SUM(CASE WHEN reseid.reported_result=refeid.reference_result THEN 1 ELSE 0 END)")))
									->join(array('spm'=>'shipment_participant_map'),'reseid.shipment_map_id=spm.map_id and refeid.shipment_id=spm.shipment_id',array())
									->where("spm.attributes LIKE '%\"extraction_assay\":\"$extId\"%' ")
									->where("spm.attributes LIKE '%\"detection_assay\":\"$detId\"%' ")
									->where("spm.final_result IS NOT NULL")
									->where("spm.final_result!=''")
									->where("substring(spm.evaluation_status,4,1) != '0'")
									->where("spm.shipment_id = ?",$shipmentId)
									->group(array("refeid.sample_id"));
							$shipmentResult['summaryResult'][]=$sQueryRes;
							$shipmentResult['summaryResult'][count($shipmentResult['summaryResult'])-1]['correctCount']=$db->fetchAll($tQuery);
						}
						
					}
				}
				//Zend_Debug::dump($shipmentResult);
				//die;
				
			}
			else if($shipmentResult['scheme_type']=='vl'){
				
			}
			
			$i++;
		}
		
		return $shipmentResult;
	}
}

