<?php

namespace app\controllers\member;

use app\models\Insurance;
use yii;
use yii\base\Action;
use app\models\ChatResults;
use app\models\Professional;
use app\models\ProfessionalExpertise;
use app\models\Expertise;
use app\models\ProfessionalCompany;
use app\models\Company;
use app\models\Localities;
use app\models\Care;
use app\models\ProfessionalAddress;
use app\models\Recommendation;
use app\models\MainSpecialization;
use app\models\MainCare;
use app\models\MainSpecializationExpertise;
use app\models\MainCareSubCare;
use app\components\ChatSearchProfessional;
use app\components\ChatSearchTherapists;
use app\components\ChatSearchProfessionalByName;
use app\models\UserInsurance;
use yii\helpers\ArrayHelper;

class ChatAction extends Action
{
    public $member;
    public function run()
    {
		$specialization_list = ArrayHelper::map(
			MainSpecialization::find()
				->where(['not in', 'id', [14]])
				->all(),
			'id',
			'name'
		);
		$care_list = ArrayHelper::map(
			MainCare::find()->all(),
			'id',
			'name'
		);

		$input = json_decode(file_get_contents('php://input'), true);

		if($input && isset($input['category']) && $input['category']) {
				$typeFilter = $input['category']['type'] ?? '';

				$searchResults = [];
				$specialty = null;
				$cares = null;
				$explanation = null;
				$objectCategory = null;
				if($typeFilter == 'mainSpecialization' || $typeFilter == 'expertises'){

					if($typeFilter == 'mainSpecialization') {
						$specialty = MainSpecialization::findOne(['id' => $input['category']['id']]);	
						$objectCategory = $specialty;	
					}

					if($typeFilter == 'expertises') {
						$explanation = Expertise::findOne(['id' => $input['category']['id']]);
						$objectCategory = $explanation;
						$mainSpecializationExpertise = MainSpecializationExpertise::findOne(['expertise_id' => $input['category']['id']]);
						$specialty	= $mainSpecializationExpertise 
							? MainSpecialization::findOne(['id' => $mainSpecializationExpertise->main_specialization_id])
							: null;
					}
					
					
					$searchResults = (new ChatSearchProfessional(Yii::$app->user->identity, 1, 
						$typeFilter == 'expertises' ? [ $input['category']['id'] ] : [],
						$typeFilter == 'mainSpecialization' ? [ $input['category']['id'] ] : ($specialty ? [$specialty->id] : []),
						$input['category']['isKids'] ?? false
					))->getAll();

				}elseif($typeFilter == 'cares' || $typeFilter == 'main_cares' ){
					if($typeFilter == 'main_cares') {
						$cares = MainCare::findOne(['id' => $input['category']['id']]);		
						$objectCategory = $cares;
					}

					if($typeFilter == 'cares') {
					
						$objectCategory = Care::findOne(['id' => $input['category']['id']]);
						$careId = MainCareSubCare::findOne(['care_id' => $input['category']['id']]);
				
		
						$specialty =  $careId 
							? MainCare::findOne(['id' => $careId->main_care_id])
							: null;	
					}

					$searchResults = (new ChatSearchTherapists(
						Yii::$app->user->identity, 1, 
						$typeFilter == 'cares' ? [ $input['category']['id'] ] : [],
						$typeFilter == 'main_cares' ? [ $input['category']['id'] ]: ( $specialty ? [$specialty->id]: []), 
						$input['category']['isKids']
					))->getAll();
				}

				$userIdentity = Yii::$app->user->identity;
				$insuranceNames = $this->getMemberInsuranceNames($userIdentity->id);
				$insuranceText  = !empty($insuranceNames) ? implode(', ', $insuranceNames) : 'אין';

				$args = [
					"specialty" => [$input['category']['id']],
					"cares"=> $cares,
					"explanation"=> $explanation ? [$explanation] : [],
					"gender"=> $userIdentity->gender == 1 ? "אישה" : ($userIdentity->gender == 0 ? "גבר" : null),			
					"hmo"=> $userIdentity->hmo,
					"insurance"=> $insuranceText,
					"address"=> $userIdentity->address,
					"name"=> "",
					"isKids"=> false,
					"mainSpecialty"=> $objectCategory ? $objectCategory->name : '',
					"userNaf"=>  $this->getNafByCity($userIdentity->address),
					"lat"=> $userIdentity->lat,
					"lng"=> $userIdentity->lng
				];
			
			return [
				'response' => "מצאתי תוצאות עבורך:",
				'args' => $args,
				'results' => $searchResults,
			];
		}

		if($input /*&& isset($input['message']*/ && isset($input['history'])){
			$userInput = $input['history'];//$input['message'];
			Yii::info($userInput);
			$apiKey = Yii::$app->params['openaiApiKey'];
			$apiGoogleKey = Yii::$app->params['google_api_key'];
			$url = 'https://api.openai.com/v1/chat/completions';
			
            if (empty($this->member)) {
				$this->member = Member::findOne(1); // דיבאג: member 1
			}

			if (empty($this->member)) {
				Yii::$app->response->statusCode = 401;
				return ['error' => 'no_member', 'response' => 'לא נמצא member (וגם אין משתמש מחובר)'];
			}

			$username = $this->member->first_name ?? '';

			$isSymptom = '';

			$specializations = '';
			foreach ($specialization_list as $id => $name) {
				$specializations .= "$id, $name\n";
			}

			$cares = '';
			foreach ($care_list as $id => $name) {
				$cares .= "$id, $name\n";
			}					

			$insuranceNames = $this->getMemberInsuranceNames($this->member->id);
			$insuranceText  = !empty($insuranceNames) ? implode(', ', $insuranceNames) : 'אין';

			$recent_chat = 'אל תחזור על שאלות שכבר נענו. אם הפרטים כבר נמסרו, זכר אותם וענה בהתאם.';
            if($most_recent_chat = $this->getMostRecentChat()){
                $recent_chat = 'המשתמש כבר התכתב איתך בעבר ומסר את הפרטים האלו.
                אין צורך לבקש ממנו שוב, אלא אם המשתמש יגיד לך שאחד הפרטים השתנה.
                תחום התמחות תמיד צריך לבקש שוב.
                להלן הפטים מפעם שעברה:
				מגדר: '.$most_recent_chat->gender.'
                קופת חולים: '.$most_recent_chat->hmo.'
                חברת ביטוח: '.$insuranceText.'
                כתובת מגורים: '.$most_recent_chat->address.'
                ';
            }
			$gender = $most_recent_chat->gender == 1 ? "אישה" : ($most_recent_chat->gender == 0 ? "גבר" : null);

			Yii::info("specializations: ".print_r($specializations,1));

			$systemPrompt = <<<PROMPT
			שלום, שם המשתמש: $username. פנה אליך בשם "$username". במגדר: {$gender}
			אתה עוזר חכם לחיפוש רופאים/מטפלים בישראל – אמפתי, תמציתי, ועונה בעברית תקנית בלבד.

			חוקי פלט:
			- טקסט → מותר רק אם נאמר במפורש בהמשך || נתונים → tool call בלבד.
			- JSON  גולמי → אסור להחזיר.
			- לעולם אל תחזיר specialty=[], cares=[] ו-name="" בו-זמנית (לפחות אחד מהם לא ריק).

			[נתוני משתמש]
			{$recent_chat}

			[דביקים מול נדיפים]
			- דביקים: gender, hmo, insurance, address (נשמרים עד שמופיע ערך חדש).
			- איפוס נתונים: specialty, cares, name (תאפס את הנתונים הבאים).

			כללי הבחירה:
			1) אם המשתמש מתאר בעיה/תסמין → החזר עד 4 (אפשר פחות) התמחויות רלוונטיות בלבד.
			- אל תחזיר תחום שמידת ההתאמה נמוכה/נדירה/לא מתאימה.
			- דרג גבוה→נמוך. אם יש אחת בולטת, מותר להחזיר רק אותה.
			- אל תחזיר רפואת ילדים אם המשתמש לא ביקש במפורש
			- לכל פריט חובה explanation קצר (3–20 מילים).
			- אם אתה מחזיר טקסט למשתמש לעולם אל תציג למשתמש את ה Id (cares/specialty)של ההתמחויות.

			2) אם התמחות נמצאת ברשימת ההתמחויות רופאים עליך להחזיר אתה id בשדה specialty
			ואם התמחות נמצאת ברשימת תחומי מטפלים עליך להחזיר אתה id בשדה cares.
			לדוגמא: "פיזותרפיה" נמצא ברשימה התמחויות רופאים לכן החזר אותו בשדה specialty.
			- לעולם אל תמציא מזהים שאינם קיימים ברשימות שהועברו.

			[כלל "ילדים" (Kids-flag)]
			- אם נכתב דפוס של **<תחום> ילדים / <תחום> לילדים / ילדים <תחום> / תחום + ילדים/נוער/מתבגרים**:
			- בחר את **התחום עצמו בלבד** (specialty או cares בהתאם), **אל** תבחר “רפואת ילדים”.
			- הגדר `isKids=true`.
			- רק אם המשתמש כתב במפורש **"רפואת ילדים"** (או "ילדים ונוער") **בלי** תחום נוסף:
			- אז ורק אז specialty=[ID_רפואת_ילדים] ו־`isKids=true`.

			[רשימת התמחויות רופאים (specialty)]
			{$specializations}

			[רשימת תחומי מטפלים (cares)]
			{$cares}

			[סכימת קריאת הכלי – ארגומנטים]
			{
			"specialty": [<IDs>],
			"cares": [<IDs>],
			"explanation": [{"id": <ID>, "reason": "<string>"}],
			"gender": "<string או ריק>",
			"hmo": "<string או ריק>",
			"insurance": "<string או ריק>",
			"address": "<string או ריק>",
			"name": "<string או ריק>",
			"isKids": <true|false>
			}

			PROMPT;

			$chat_history = [
			  ["role" => "system", "content" => $systemPrompt],
			];
			$chat_history = array_merge($chat_history,$userInput);
			Yii::info($chat_history);
			
			$tools = [[
			  "type" => "function",
			  "function" => [
				"name" => "collect_user_criteria",
				"description" => "איסוף פרטי חיפוש רופא מהמשתמש",
				"parameters" => [
				  "type" => "object",
				  "properties" => [
					"specialty" => [
					  "type" => "array",
						"items" => [
							"type" => "integer"
						],
						"description" => "תחומי ההתמחות של הרופא, לדוגמא: 7 עבור 'רפואת ריאות', 58 עבור 'פיזותרפיה' וכו'.",
					],
					"cares" => [
					  "type" => "array",
						"items" => [
							"type" => "integer"
						],
						"description" => "תחומי ההתמחות של המטפל, לדוגמא:  3 עבור 'פסיכולוגיה', 7 עבור 'טיפול באמנות' וכו'.",
					],
					"explanation" => [
						"type" => "array",
						"description" => "רשימת הסברים לפי מזהה שנבחר",
						"items" => [
							"type" => "object",
							"properties" => [
							"id" => ["type" => "integer", "description" => "המזהה שנבחר (specialty או cares)"],
							"reason" => ["type" => "string", "description" => "הסיבה לבחירה"]
							],
							"required" => ["id","reason"]
						]
					],
					"gender" => [
						"type" => "string",
						"description" => "המגדר של המשתמש גבר או אישה",
						"enum" => [
							"אישה",
							"גבר",
						]
					],
					"hmo" => [
					  "type" => "string",
					  "description" => "שם קופת החולים (למשל: מכבי, כללית, לאומית, מאוחדת)",
					  "enum" => [
						"מאוחדת",
						"כללית",
						"מכבי",
						"לאומית",
						"כללית מושלם",
						"לאומית משלים",
						"מכבי משלים",
						"אין",
					  ]
					],
					"insurance" => [
					  "type" => "string",
					  "description" => "(למשל: מגדל, כלל, מנורה, אילון, הפניקס, הראל) חברת ביטוח",
					  "enum" => [
						"מגדל",
						"כלל",
						"מנורה",
						"אילון",
						"הפניקס",
						"הכשרה",
						"הראל",
						"אין",
					  ]
					],
					"address" => [
					  "type" => "string",
					  "description" => "כתובת מגורים או כל הארץ" 
					],
					"name" => [
						"type" => "string",
					    "description" => "שם של רופא בעברית" 
					],
					"isKids" => [
						"type" => "boolean",
					    "description" => "האם המשתמש מחפש רופא שמטפל בילדים בנוסף להתמחות ראשית"
					]
				  ],
				  "required" => ["specialty", "cares", "explanation", "gender", "hmo", "insurance", "address", "name", "isKids"]
				]
			  ]
			]];

			$data = [
			  	"model" => "gpt-5.2",
				//"model" => "gpt-4o",
				"messages" => $chat_history,
				"user" => 'doctorita_' . $this->member->id,
				"temperature" => 1,
				// "max_completion_tokens" => 2048, 
				// "max_tokens" => 2048, 
				"tools" => $tools,
				"tool_choice" => "auto",
				// "reasoning_effort" => "minimal",
				// "verbosity" => "low",
			  //"stream" => true,
			];

			Yii::info("data to GPT: ".print_r($data,1));


			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			]);


			$response = curl_exec($ch);
			curl_close($ch);
			
			Yii::info($response);

			$result = json_decode($response, true);
			if (isset($result['choices'][0]['finish_reason']) && $result['choices'][0]['finish_reason'] === 'tool_calls') {
				$functionCall = $result['choices'][0]['message']['tool_calls'][0]['function'];
				$args = json_decode($functionCall['arguments'], true);
				$specialtyArray = $args['specialty'] ?? [];
				$careArray = $args['cares'] ?? [];
				$insuranceNames = $this->getMemberInsuranceNames($this->member->id);
				$args['insurance'] = $insuranceNames;

				if(!empty($args['name'])){
					$isSymptom = '';
					$result = $this->search_doctors_by_name_api($args['name'] ,$specialtyArray,$careArray , $args['gender'], $args['hmo'], $args['insurance'], $args['address'] ,$args['isKids'],$apiGoogleKey,$most_recent_chat);
					$professionals = $result['professionals'];
					$args['userNaf'] = $this->getNafByCity($args['address']);
					$args['lat'] = $result['lat'];
					$args['lng'] = $result['lng'];

					return [
						'response' => "מצאתי תוצאות עבורך:",
						'args' => $args,
						'results' => $professionals,
					];

				}
				if ((count($specialtyArray) > 1) || (count($careArray) > 1)) {
					
					$isSymptom = $userInput;
					$options = [];
					
					if (count($specialtyArray) > 1) {
						foreach ($specialtyArray as $specId) {
							if (isset($specialization_list[$specId])) {
								$explanation = null;
								foreach ($args['explanation'] as $exp) {
									if ($exp['id'] == $specId) {
										$explanation = $exp['reason'];
										break;
									}
								}

								$options[] = [
									'type' => 'specialty',
									'id' => $specId,
									'name' => $specialization_list[$specId],
									'description' => $explanation,
								];
							}
						}
					}
					
					if (count($careArray) > 1) {
						foreach ($careArray as $careId) {
							$explanation = null;
							foreach ($args['explanation'] as $exp) {
								if ($exp['id'] == $careId) {
									$explanation = $exp['reason'];
									break;
								}
							}

							if (isset($care_list[$careId])) {
								$options[] = [
									'type' => 'care',
									'id' => $careId,
									'name' => $care_list[$careId],
									'description' => $explanation,
								];
							}
						}
					}

					return [
						'response' => "מצאתי כמה אפשרויות מתאימות עבורך. אנא בחר:",
						'showOptions' => true,
						'options' => $options
					];
				}
				$resultSecound = $this->chetExpertise($args, $userInput, $apiKey ,$specialization_list, $care_list, $url, $isSymptom);
				
				if(!empty($resultSecound['choices'][0]['message']['tool_calls'])){
					$functionCall2 = $resultSecound['choices'][0]['message']['tool_calls'][0]['function'];
					$args2 = json_decode($functionCall2['arguments'], true);

					// כאן אתה קורא ל-API של דוקטוריטה:
					$searchResults = $this->search_doctors_api($args2['expertise'],$args['specialty'], $args['cares'],$args['gender'], $args['hmo'], $args['insurance'], $args['address'], $args['isKids'],$apiGoogleKey,$most_recent_chat);
					$professionals = $searchResults['professionals'];
					$args['mainSpecialty'] = $args['specialty'] !== [] ? $specialization_list[$args['specialty'][0]] : '';
					$args['cares'] = $args['cares'] !== [] ? $care_list[$args['cares'][0]] : '';
					$args['specialty'] = $args2['expertise'];
					$expertise = Expertise::findOne($args2['expertise']);
					$args['specialtyName'] = $expertise ? $expertise->name : null;
					$args['userNaf'] = $this->getNafByCity($args['address']);


					$args['lat'] = $searchResults['lat'];
					$args['lng'] = $searchResults['lng'];
					return [
						'response' => "מצאתי תוצאות עבורך:",
						'args' => $args,
						'results' => $professionals,
					];
				}elseif(isset($data['choices'][0]['message']['function_call'])){
			
						$functionCall2 = $data['choices'][0]['message']['function_call'];
						$args2 = json_decode($functionCall2['arguments'], true);


						// כאן אתה קורא ל-API של דוקטוריטה:
						$searchResults = $this->search_doctors_api($args2['expertise'],$args['specialty'], $args['cares'],$args['gender'], $args['hmo'], $args['insurance'], $args['address'], $args['isKids'],$apiGoogleKey,$most_recent_chat);
						$professionals = $searchResults['professionals'];
						$args['mainSpecialty'] = $args['specialty'] !== [] ? $specialization_list[$args['specialty'][0]] : '';
						$args['cares'] = $args['cares'] !== [] ? $care_list[$args['cares'][0]] : '';
						$args['specialty'] = $args2['expertise'];
						$expertise = Expertise::findOne($args2['expertise']);
						$args['specialtyName'] = $expertise ? $expertise->name : null;
						$args['userNaf'] = $this->getNafByCity($args['address']);
						
						$args['lat'] = $searchResults['lat'];
						$args['lng'] = $searchResults['lng'];
						
						return [
							'response' => "מצאתי תוצאות עבורך:",
							'args' => $args,
							'results' => $professionals,
						];
					
				}else{
					// עדיין באיסוף מידע – תחזיר תשובה רגילה
					$content = $resultSecound['choices'][0]['message']['content'] ?? '';
					return ['response' => $content];
				}

			} else if (isset($data['choices'][0]['message']['function_call'])) {

				$functionCall = $data['choices'][0]['message']['function_call'];
				$args = json_decode($functionCall['arguments'], true);
				$insuranceNames = $this->getMemberInsuranceNames($this->member->id);
				$args['insurance'] = $insuranceNames;
				$specialtyArray = $args['specialty'] ?? [];
				$careArray = $args['cares'] ?? [];
				if(!empty($args['name'])){
					$result = $this->search_doctors_by_name_api($args['name'],$specialtyArray,$careArray, $args['gender'], $args['hmo'], $args['insurance'], $args['address'] ,$args['isKids'],$apiGoogleKey,$most_recent_chat);
					$professionals = $result['professionals'];
					$args['userNaf'] = $this->getNafByCity($args['address']);
					$args['lat'] = $result['lat'];
					$args['lng'] = $result['lng'];
					return [
						'response' => "מצאתי תוצאות עבורך:",
						'args' => $args,
						'results' => $professionals,
					];

				}
				$resultSecound = $this->chetExpertise($args, $userInput, $apiKey , $specialization_list, $care_list, $url, $isSymptom);
				
				if(!empty($resultSecound)){
					$functionCall2 = $data['choices'][0]['message']['function_call'];
					$args2 = json_decode($functionCall2['arguments'], true);

					// כאן אתה קורא ל-API של דוקטוריטה:
					$searchResults = $this->search_doctors_api($args2['expertise'], $args['specialty'], $args['cares'], $args['gender'], $args['hmo'], $args['insurance'], $args['address'], $args['isKids'],$apiGoogleKey,$most_recent_chat);
					$professionals = $searchResults['professionals'];
					$args['mainSpecialty'] = $args['specialty'] !== [] ? $specialization_list[$args['specialty'][0]] : '';
					$args['cares'] = $args['cares'] !== [] ? $care_list[$args['cares'][0]] : '';
					$args['specialty'] = $args2['expertise'];
					$expertise = Expertise::findOne($args2['expertise']);
					$args['specialtyName'] = $expertise ? $expertise->name : null;
					$args['userNaf'] = $this->getNafByCity($args['address']);


					$args['lat'] = $searchResults['lat'];
					$args['lng'] = $searchResults['lng'];
					return [
						'response' => "מצאתי תוצאות עבורך:",
						'args' => $args,
						'results' => $professionals,
					];
				}
			} else {
				// עדיין באיסוף מידע – תחזיר תשובה רגילה
				$content = $result['choices'][0]['message']['content'] ?? '';
				return ['response' => $content];
			}
		}
		else
			return ['success' => true];
    }

    public function search_doctors_by_name_api($name, $mainSpecialization, $cares, $gender, $hmo, $insurance, $address, $isKids ,$apiGoogleKey, $mostRecentChat){
		$data = new ChatResults();
        $data->member_id = $this->member->id;
		if($mainSpecialization !== null && $mainSpecialization !== [] ){
			$data->specialty = is_array($mainSpecialization) ? (string) $mainSpecialization[0] : (string) $mainSpecialization;
		}elseif($cares !== null && $cares !== [] ){
			$data->care = is_array($cares) ? (string) $cares[0] : (string) $cares;
		}
		$data->name = $name;
		$data->is_pediatric = $isKids;

		$latSend = 0;
		$lngSend = 0;

		if($mostRecentChat === null || $mostRecentChat->address === null || ($mostRecentChat->address !== $address && $address!== null && $address!== '' && $address!== "אין" && $address!== "כל הארץ")){
			$latLang = $this->getLatLng($address,$apiGoogleKey);
			$latSend = $latLang['lat'];
			$lngSend = $latLang['lng'];

		}elseif($mostRecentChat->address === $address){
			$latSend = floatval($mostRecentChat->lat);
			$lngSend = floatval($mostRecentChat->lng);
		}
		$data->save();

		$member = $this->member;
		if (!$member) {
			Yii::$app->response->statusCode = 401;
			return ['professionals' => [], 'lat' => 0, 'lng' => 0, 'error' => 'unauthorized'];
		}

		$professionals = (new ChatSearchProfessionalByName($member, $name, $mainSpecialization, $cares, $address, $isKids, $latSend, $lngSend ))->getAll();
		return [
			'professionals' => $professionals,
			'lat' => $latSend,
			'lng' => $lngSend,
		];
	}
	public function search_doctors_api($specialty, $mainSpecialization, $cares, $gender, $hmo, $insurance, $address, $isKids, $apiGoogleKey, $mostRecentChat) {
        $data = new ChatResults();
        $data->member_id = $this->member->id;
		if($mainSpecialization !== null && $mainSpecialization !== [] ){
			$data->specialty = is_array($mainSpecialization) ? (string) $mainSpecialization[0] : (string) $mainSpecialization;
		}elseif($cares !== null && $cares !== [] ){
			$data->care = is_array($cares) ? (string) $cares[0] : (string) $cares;
		}
		
		$data->is_pediatric = $isKids;
		
		// Initialize coordinates (default to 0 if not provided)
		$latSend = 0;
		$lngSend = 0;

		// Calculate lat/lng from address if:
		// - No previous chat exists, OR
		// - Previous chat has no address, OR
		// - Address has changed (and is not empty/special values)
		// Source: $address comes from $args['address'] (GPT response)
		if($mostRecentChat === null || $mostRecentChat->address === null || ($mostRecentChat->address !== $address && $address!== null && $address!== "אין" && $address!== "כל הארץ")){
			// Use Google Geocoding API to convert address to coordinates
			$latLang = $this->getLatLng($address,$apiGoogleKey);
			if ($latLang) {
				$latSend = $latLang['lat'];
				$lngSend = $latLang['lng'];
			}
			
		// If address hasn't changed, reuse coordinates from previous chat (performance optimization)
		}elseif($mostRecentChat->address === $address){
			$latSend = floatval($mostRecentChat->lat);
			$lngSend = floatval($mostRecentChat->lng);

		}
		$data->save();
		
		

		$member = $this->member;
		if (!$member) {
			Yii::$app->response->statusCode = 401;
			return ['professionals' => [], 'lat' => 0, 'lng' => 0, 'error' => 'unauthorized'];
		}

		$hmo = Company::find()->where(['name' => $hmo])->one();
		
		$insRows = UserInsurance::find()
			->alias('ui')
			->select(['ui.insurance_id', 'i.name'])
			->innerJoin(['i' => Insurance::tableName()], 'i.id = ui.insurance_id')
			->where(['ui.member_id' => $member->id])
			->asArray()
			->all();

		$insurance_id = array_map('intval', array_column($insRows, 'insurance_id'));
		$expertises = $specialty;
		$hmo_id = $hmo ? $hmo->id : 0;
		$page = 1;
		$isKids = $isKids ? $isKids : false;

		$professionals = [];
		
		// ====================================================================
		// ChatSearchProfessional Constructor Arguments (line 957):
		// ====================================================================
		// 1. $member: Authenticated member object (for personalized recommendations)
		// 2. $page: Page number for pagination (1)
		// 3. $expertises: Array of expertise IDs from chetExpertise() [$args2['expertise']]
		// 4. $mainSpecialization: Array of main specialization IDs from GPT [$args['specialty']]
		// 5. $isKids: Boolean flag for pediatric search [$args['isKids']]
		// 6. $hmo_id: Company ID converted from HMO name [$args['hmo'] -> Company::find()]
		// 7. $insurance_id: Array of insurance IDs from member's profile [UserInsurance]
		// 8. $address: City/address string from GPT response [$args['address']]
		// 9. $latSend: Latitude calculated from address or from previous chat [getLatLng()]
		// 10. $lngSend: Longitude calculated from address or from previous chat [getLatLng()]
		// ====================================================================
		if($mainSpecialization !== null && $mainSpecialization !== [] ){
			$professionals = (new ChatSearchProfessional($member, $page, $expertises,$mainSpecialization, $isKids, $hmo_id, $insurance_id,$address,$latSend,$lngSend))->getAll();
		}elseif($cares !== null && $cares !== [] ){
			// Same arguments for therapists search
			$professionals = (new ChatSearchTherapists($member, $page, $expertises,$cares, $isKids, $hmo_id, $insurance_id,$address,$latSend,$lngSend))->getAll();
		}
	   return [
			'professionals' => $professionals,
			'lat' => $latSend,
			'lng' => $lngSend,
		];

	
	}

	private function getLatLng($address,$apiGoogleKey)
    {
        $addressEncoded = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addressEncoded}&language=he&key={$apiGoogleKey}";

        $response = file_get_contents($url);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if ($data['status'] === 'OK') {
            $result = $data['results'][0];
            $location = $result['geometry']['location'];

            $addressParts = $this->extractAddressParts($result);

			if (isset($addressParts['lat']) && isset($addressParts['lng'])) {
				return [
					'lat' => $addressParts['lat'],
					'lng' => $addressParts['lng'],
				];
			}
        }

        return null;
    }

    function extractAddressParts(array $result): array {
        $components = $result['address_components'] ?? [];
    
        $pick = function (array $typePriority) use ($components): string {
            foreach ($typePriority as $wanted) {
                foreach ($components as $c) {
                    if (!empty($c['types']) && in_array($wanted, $c['types'], true)) {
                        return (string)($c['long_name'] ?? '');
                    }
                }
            }
            return '';
        };
    
        return [
            'lat' => $result['geometry']['location']['lat'] ?? null,
            'lng' => $result['geometry']['location']['lng'] ?? null,
        ];
    }
	public function getMostRecentChat() {
		return $this->member;
	}
	
	private function getMemberInsuranceNames(int $memberId) {
		$rows = UserInsurance::find()
			->alias('ui')
			->select('i.name')
			->innerJoin(['i' => Insurance::tableName()], 'i.id = ui.insurance_id')
			->where(['ui.member_id' => $memberId])
			->column();

		return array_values(array_unique(array_filter($rows)));
	}

	public function chetExpertise( $args, $userInput, $apiKey, $specialization_list, $care_list, $url, $isSymptom ){
		

		$specialtyText = '';
		$careText = '';
		$specialtyName = '';
		$careName = '';

		if($args['specialty'] !== null && $args['specialty'] !== []){
			$expertiseIds = MainSpecializationExpertise::find()
				->select('expertise_id')
				->where(['main_specialization_id' => $args['specialty']])
				->column();

			$expertiseList = Expertise::find()
				->select(['id', 'name'])
				->where(['id' => $expertiseIds])
				->asArray()
				->all();
			
			foreach ($expertiseList as $exp) {
				$specialtyText .= $exp['id'] . ', ' . $exp['name'] . "\n";
			}
			$expertiseMap = array_column($expertiseList, 'name', 'id');
			
			$specialtyKey = is_array($args['specialty']) ? $args['specialty'][0] : $args['specialty'];
			$specialtyName = $specialization_list[$specialtyKey] ?? null;

		}elseif($args['cares'] !== null && $args['cares'] !== []){

			$careIds = MainCareSubCare::find()
				->select('care_id')
				->where(['main_care_id' => $args['cares']])
				->column();

			$careList = Care::find()
				->select(['id', 'name'])
				->where(['id' => $careIds])
				->asArray()
				->all();
			
			foreach ($careList as $care) {
				$careText .= $care['id'] . ', ' . $care['name'] . "\n";
			}
			$careMap = array_column($careList, 'name', 'id');
			
			$careKey = is_array($args['cares']) ? $args['cares'][0] : $args['cares'];
			$careName = $care_list[$careKey] ?? null;
		}

		$expertiseName = '';
		$expertiseText = '';
		if($specialtyName !== ''){
			$expertiseName = $specialtyName;
			$expertiseText = $specialtyText;
		}elseif($careName !== ''){
			$expertiseName = $careName;
			$expertiseText = $careText;
		}

		$needle = 'מצאתי כמה אפשרויות מתאימות עבורך. אנא בחר:';
		Yii::info("userInput: ".print_r($userInput,1));
		$prevMsg = $this->getPrevMessageIfThirdFromEnd($userInput, $needle);
		if ($prevMsg !== null) {
			$isSymptom = (string)($prevMsg['content'] ?? ($prevMsg['response'] ?? ''));
		}

		$systemPrompt = <<<PROMPT
		המשימה שלך למצוא את תתי ההתמחויות עבור ההתמחות הראשית: {$expertiseName} הרלוונטיות ביותר רק מתוך הרשימה הבאה לבעיה או התסמין שהמשתמש תיאר: {$isSymptom}.
		במידה והם לא רלוונטיות אל תחזיר אותם.

		מתוך הרשימה הבאה של תת-התמחויות:
		{$expertiseText}

		החזר את התוצאה במבנה JSON הבא (ללא הסבר נוסף) באמצעות פונקציית JSON:
		{ "expertise": "[...,...]" }
		PROMPT;

			
		$chat_history = [
			["role" => "system", "content" => $systemPrompt],
		];
		$chat_history = array_merge($chat_history,$userInput);
		Yii::info($chat_history);

		$tools = [[
			"type" => "function",
			"function" => [
				"name" => "select_sub_specialties",
				"description" => "איסוף תתי-התמחויות שהמשתמש ביקש",
				"parameters" => [
					"type" => "object",
					"properties" => [
						"expertise" => [
							"type" => "array",
							"items" => ["type" => "integer"],
							"description" => "מערך מזהים של תתי-התמחויות"
						]
					],
					"required" => ["expertise"]
				]
			]
		]];


		

		$data = [
		  	"model" => "gpt-5.2", 
		  	"messages" => $chat_history,
			"temperature" => 1,
			// "max_tokens" => 2048,
			"tools" => $tools,
			"tool_choice" => [
				"type" => "function",
				"function" => ["name" => "select_sub_specialties"]
			],
			// "reasoning_effort" => "minimal",
			//"stream" => true,
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $apiKey,
		]);


		$response = curl_exec($ch);
		curl_close($ch);
			
		Yii::info($response);

		$result = json_decode($response, true);
		return $result;
	}

	private function getNafByCity($cityName)
	{
		$cleanCityName = str_replace(' ', '', $cityName);
		
		return Localities::find()
			->select(['localities.city_name', 'localities.city_symbol', 'localities.naf_name', 'localities.naf_symbol'])
			->where(['REPLACE(city_name, " ", "")' => $cleanCityName])
			->one();
	}

	function getPrevMessageIfThirdFromEnd(array $history, string $needle): ?array
	{
		$n = count($history);
		if ($n < 3) return null;

		for ($i = 0; $i < $n; $i++) {
			$content = (string)($history[$i]['content'] ?? ($history[$i]['response'] ?? ''));
			if ($content === '') continue;

			if (mb_strpos($content, $needle) !== false) {
				if ($i === $n - 2) {
					return $history[$i - 1] ?? null;
				}
			}
		}
		return null;
	}

}