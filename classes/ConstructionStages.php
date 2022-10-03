<?php

function validate_loop($errors){
	/*	Checks for set error flags from $errors

		@param array $errors array(str => bool) - array of error flags
		@return => array() / false - array for error / false for no errors
	*/
	foreach ($errors as $key => $value) {
		if ($value){
			return ["ERROR", $key];
		}
	}

	return false;
}


function validate_input($data){
	/* 	Used to validate input class

		@param ConstructionStagesCreate/ConstructionStagesPatch $data The input data
		@return true/["ERROR", error message]
	*/

	# dictionary for errors linked with each field in $data
	$field_checks = [
		"name" => [
			"Property name [$data->name] must be of type text" => (gettype($data->name) != "string"),
			"Property name should be smaller than 255 characters." => strlen($data->name) > 255
		],
		"startDate" => [
			"Property startDate [$data->startDate] doesn't match the correct date format" => !preg_match("[[0-9]+-[0-9]+-[0-9]+T[0-9]+:[0-9]+:[0-9]+.[0-9]+Z]", $data->startDate) and $data->startDate !== null,
			"Property startDate isn't a valid date [$data->startDate]" => !((bool)strtotime($data->startDate)) and $data->startDate !== null
		],
		"endDate" => [
			"Property endDate doesn't match the correct date format" => !preg_match("[[0-9]+-[0-9]+-[0-9]+T[0-9]+:[0-9]+:[0-9]+.[0-9]+Z]", $data->endDate) and $data->endDate !== null,
			"Property endDate isn't a valid date" => !((bool)strtotime($data->endDate)) and $data->endDate !== null,
			"startDate should be smaller than endDate" => (strtotime($data->endDate) < strtotime($data->startDate)) and $data->endDate !== null
		],
		"durationUnit" => [
			"Property durationUnit [$data->durationUnit] can only be equal to HOURS, DAYS or WEEKS" => !in_array($data->durationUnit, ["HOURS", "DAYS", "WEEKS", null])
		],
		"color" => [
			"Property color isn't in valid format" => !($data->color == null or preg_match("[#[0-9A-Fa-f]{6}$]", $data->color)) 
		],
		"externalId" => [
			"Property externalId [$data->externalId] must be of type text" => (gettype($data->externalId) != "string"),
			"Property externalId must be smaller than 255 characters" => strlen($data->externalId) > 255
		],
		"status" => [
			"Property status [$data->status] can only be equal to NEW, PLANNED or DELETED" => !in_array($data->status, ["NEW", "PLANNED", "DELETED", null])
		]
	];

	foreach ($field_checks as $field => $errors){
		if (property_exists($data, $field)){
			if (validate_loop($errors) !== false){				# "!== false" just for sure
				return validate_loop($errors);					# return error
			};
		}
	}

	return true;
}


function calculate_additional_data($data){
	/*	Used to calculate $data->duration
		@param ConstructionStagesCreate/ConstructionStagesPatch $data The input data
	*/


	// check if duration can be calculated
	if ($data->startDate === null or $data->endDate === null){
		$data->duration = null;
		return null;
	}

	if ($data->durationUnit === null){
		$data->durationUnit = "DAYS";
	}

	// drop time and calculate difference between dates
	$data->startDate = date_format(date_create($data->startDate), "Y-m-d\TH:00:00\Z");
	$data->endDate = date_format(date_create($data->endDate), "Y-m-d\TH:00:00\Z");
	$diff = strtotime($data->endDate) - strtotime($data->startDate);	# calculate the difference

	switch ($data->durationUnit) {	# calculate duration
		case 'DAYS':
			$a_day = 60*60*24;	# in seconds
			$data->duration = round($diff / $a_day, 2, PHP_ROUND_HALF_DOWN);
			break;
		
		case 'HOURS':
			$an_hour = 60*60;	# in seconds
			$data->duration = round($diff / $an_hour, 2, PHP_ROUND_HALF_DOWN);
			break;

		case 'WEEKS':
			$a_week = 60*60*24*7; # in seconds
			$data->duration = round($diff / $a_week, 2, PHP_ROUND_HALF_DOWN);
			break;

		default:
			echo "There must be something wrong with the durationUnit. Please check";
			break;
	}
}


class ConstructionStages
{
	private $db;

	public function __construct()
	{
		$this->db = Api::getDb();
	}

	public function getAll()
	{
		$stmt = $this->db->prepare("
			SELECT
				ID as id,
				name, 
				strftime('%Y-%m-%dT%H:%M:%SZ', start_date) as startDate,
				strftime('%Y-%m-%dT%H:%M:%SZ', end_date) as endDate,
				duration,
				durationUnit,
				color,
				externalId,
				status
			FROM construction_stages
		");
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getSingle($id)
	{
		$stmt = $this->db->prepare("
			SELECT
				ID as id,
				name, 
				strftime('%Y-%m-%dT%H:%M:%SZ', start_date) as startDate,
				strftime('%Y-%m-%dT%H:%M:%SZ', end_date) as endDate,
				duration,
				durationUnit,
				color,
				externalId,
				status
			FROM construction_stages
			WHERE ID = :id
		");
		$stmt->execute(['id' => $id]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function post(ConstructionStagesCreate $data)
	{
		$stmt = $this->db->prepare("
			INSERT INTO construction_stages
			    (name, start_date, end_date, duration, durationUnit, color, externalId, status)
			    VALUES (:name, :start_date, :end_date, :duration, :durationUnit, :color, :externalId, :status)
			");
		$stmt->execute([
			'name' => $data->name,
			'start_date' => $data->startDate,
			'end_date' => $data->endDate,
			'duration' => $data->duration,
			'durationUnit' => $data->durationUnit,
			'color' => $data->color,
			'externalId' => $data->externalId,
			'status' => $data->status,
		]);
		return $this->getSingle($this->db->lastInsertId());
	}


	public function patch(ConstructionStagesPatch $data)
	{
		$input_validation = validate_input($data);
		if ($input_validation){
			calculate_additional_data($data);
		}

		return $input_validation;

		$stmt = $this->db->prepare("
			UPDATE construction_stages
			    SET name = :name, 
			    	start_date = :start_date,
			    	end_date = :end_date, 
			    	duration = :duration,
			    	durationUnit = :durationUnit, 
			    	color = :color, 
			    	externalId = :externalId, 
			    	status = :status
			    WHERE
			    	ID = :id
			");
		$stmt->execute([
			'id' => $data->id,
			'name' => $data->name,
			'start_date' => $data->startDate,
			'end_date' => $data->endDate,
			'duration' => $data->duration,
			'durationUnit' => $data->durationUnit,
			'color' => $data->color,
			'externalId' => $data->externalId,
			'status' => $data->status,
		]);
		return $this->getSingle($this->db->lastInsertId());
	}

	public function delete($id)
	{
		echo $id;
		$stmt = $this->db->prepare("
			UPDATE construction_stages
			    SET 
			    	status = :status
			    WHERE
			    	ID = :id
			");
		$stmt->execute([
			'id' => $id,
			'status' => "DELETED"
		]);
		return $this->getSingle($this->db->lastInsertId());
	}
}