<?php
/*
 * Vormt basically de koppeling tussen DB en NNTP, waarbij de db als een soort
 * cache dient
 */
class SpotsOverview {
	private $_db;
	private $_cache;
	private $_settings;
	private $_activeRetriever;

	function __construct(SpotDb $db, SpotSettings $settings) {
		$this->_db = $db;
		$this->_settings = $settings;
		$this->_cache = new SpotCache($db);
		$this->_spotImage = new SpotImage($db);
	} # ctor
	
	/*
	 * Geef een volledig Spot array terug
	 */
	function getFullSpot($msgId, $ourUserId, $nntp) {
		SpotTiming::start('SpotsOverview::' . __FUNCTION__);

		$fullSpot = $this->_db->getFullSpot($msgId, $ourUserId);
		
		if (empty($fullSpot)) {
			/*
			 * Retrieve a full loaded spot from the NNTP server
			 */
			$newFullSpot = $nntp->getFullSpot($msgId);
			$this->_db->addFullSpots( array($newFullSpot) );
			
			/*
			 * We ask our DB to retrieve the fullspot again, this ensures
			 * us all information is present and in always the same format
			 */
			$fullSpot = $this->_db->getFullSpot($msgId, $ourUserId);
		} # if

		/**
		 * We'll overwrite our spot info from the database with some information we parse from the 
		 * XML. This is necessary because the XML contains better encoding.
		 *
		 * For example take the titel from spot bdZZdJ3gPxTAmSE%40spot.net.
		 *
		 * We cannot use all information from the XML because because some information just
		 * isn't present in the XML file
		 */
		$spotParser = new SpotParser();
		$parsedXml = $spotParser->parseFull($fullSpot['fullxml']);
		$fullSpot = array_merge($parsedXml, $fullSpot);
		$fullSpot['title'] = $parsedXml['title'];
		
		/*
		 * If the current spotterid is empty, we probably now
		 * have a spotterid because we have the fullspot.
		 */
		if ((empty($fullSpot['spotterid'])) && ($fullSpot['verified'])) {
			$spotSigning = new SpotSigning($this->_db, $this->_settings);
			$fullSpot['spotterid'] = $spotSigning->calculateSpotterId($fullSpot['user-key']['modulo']);
		} # if

		/*
		 * When we retrieve a fullspot entry but there is no spot entry the join in our DB query
		 * causes us to never get the spot, hence we throw this exception
		 */
		if (empty($fullSpot)) {
			throw new Exception("Spot is not in our Spotweb database");
		} # if
		SpotTiming::stop('SpotsOverview::' . __FUNCTION__, array($msgId, $ourUserId, $nntp, $fullSpot));
		
		return $fullSpot;
	} # getFullSpot

	/*
	 * Callback functie om enkel verified 'iets' terug te geven
	 */
	function cbVerifiedOnly($x) {
		return $x['verified'];
	} # cbVerifiedOnly
	
	/*
	 * Geef de lijst met comments terug 
	 */
	function getSpotComments($userId, $msgId, $nntp, $start, $length) {
		if (!$this->_settings->get('retrieve_comments')) {
			return array();
		} # if
	
		# Bereken wat waardes zodat we dat niet steeds moeten doen
		$totalCommentsNeeded = ($start + $length);
		
		SpotTiming::start(__FUNCTION__);

		# vraag een lijst op met comments welke in de database zitten en
		# als er een fullcomment voor bestaat, vraag die ook meteen op
		$fullComments = $this->_db->getCommentsFull($userId, $msgId);
		
		# Nu gaan we op zoek naar het eerste comment dat nog volledig opgehaald
		# moet worden. Niet verified comments negeren we.
		$haveFullCount = 0;
		$lastHaveFullOffset = -1;
		$retrievedVerified = 0;
		$fullCommentsCount = count($fullComments);
		for ($i = 0; $i < $fullCommentsCount; $i++) {
			if ($fullComments[$i]['havefull']) {
				$haveFullCount++;
				$lastHaveFullOffset = $i;
				
				if ($fullComments[$i]['verified']) {
					$retrievedVerified++;
				} # if
			} # if
		} # for
		
		# en haal de overgebleven comments op van de NNTP server
		if ($retrievedVerified < $totalCommentsNeeded) {
			# Als we de comments maar in delen moeten ophalen, gaan we loopen tot we
			# net genoeg comments hebben. We moeten wel loopen omdat we niet weten 
			# welke comments verified zijn tot we ze opgehaald hebben
			if (($start > 0) || ($length > 0)) {
				$newComments = array();
			
				# en ga ze ophalen
				while (($retrievedVerified < $totalCommentsNeeded) && ( ($lastHaveFullOffset) < count($fullComments) )) {
					SpotTiming::start(__FUNCTION__. ':nntp:getComments()');
					$tempList = $nntp->getComments(array_slice($fullComments, $lastHaveFullOffset + 1, $length));
					SpotTiming::stop(__FUNCTION__ . ':nntp:getComments()', array(array_slice($fullComments, $lastHaveFullOffset + 1, $length), $start, $length));
				
					$lastHaveFullOffset += $length;
					foreach($tempList as $comment) {
						$newComments[] = $comment;
						if ($comment['verified']) {
							$retrievedVerified++;
						} # if
					} # foreach
				} # while
			} else {
				$newComments = $nntp->getComments(array_slice($fullComments, $lastHaveFullOffset + 1, count($fullComments)));
			} # else
			
			# voeg ze aan de database toe
			$this->_db->addFullComments($newComments);
			
			# en voeg de oude en de nieuwe comments samen
			$fullComments = $this->_db->getCommentsFull($userId, $msgId);
		} # foreach
		
		# filter de comments op enkel geverifieerde comments
		$fullComments = array_filter($fullComments, array($this, 'cbVerifiedOnly'));

		# geef enkel die comments terug die gevraagd zijn. We vragen wel alles op
		# zodat we weten welke we moeten negeren.
		if (($start > 0) || ($length > 0)) {
			$fullComments = array_slice($fullComments , $start, $length);
		} # if
		
		# omdat we soms array elementen unsetten, is de array niet meer
		# volledig oplopend. We laten daarom de array hernummeren
		SpotTiming::stop(__FUNCTION__, array($msgId, $start, $length));
		return $fullComments;
	} # getSpotComments()

	/*
	 * Pre-calculates the amount of new spots
	 */
	function cacheNewSpotCount() {
		$statisticsUpdate = array();

		/*
		 * Update the filter counts for the users.
		 *
		 * Basically it compares the lasthit of the session with the lastupdate
		 * of the filters. If lasthit>lastupdate, it will store the lastupdate as
		 * last counters read, hence we need to do it here and not at the end.
		 */
		$this->_db->updateCurrentFilterCounts();
		
		/*
		 * First we want a unique list of all currently
		 * created filter combinations so we can determine
		 * its' spotcount
		 */
		$filterList = $this->_db->getUniqueFilterCombinations();

		/* We add a dummy entry for 'all new spots' */
		$filterList[] = array('id' => 9999, 'userid' => -1, 'filtertype' => 'dummyfilter', 
							'title' => 'NewSpots', 'icon' => '', 'torder' => 0, 'tparent' => 0,
							'tree' => '', 'valuelist' => 'New:0', 'sorton' => '', 'sortorder' => '');
		
		/*
		 * Now get the current number of spotcounts for all
		 * filters. This allows us to add to the current number
		 * which is a lot faster than just asking for the complete
		 * count
		 */
		$cachedList = $this->_db->getCachedFilterCount(-1);

		/*
		 * Loop throug each unique filter and try to calculate the
		 * total amount of spots
		 */
		foreach($filterList as $filter) {
			# Reset the PHP timeout timer
			set_time_limit(960);
			
			# Calculate the filter hash
			$filter['filterhash'] = sha1($filter['tree'] . '|' . urldecode($filter['valuelist']));
			$filter['userid'] = -1;

			#echo 'Calculating hash for: "' . $filter['tree'] . '|' . $filter['valuelist'] . '"' . PHP_EOL;
			#echo '         ==> ' . $filter['filterhash'] . PHP_EOL;
			
			# Check to see if this hash is already in the database
			if (isset($cachedList[$filter['filterhash']])) {
				$filter['lastupdate'] = $cachedList[$filter['filterhash']]['lastupdate'];
				$filter['lastvisitspotcount'] = $cachedList[$filter['filterhash']]['currentspotcount'];
				$filter['currentspotcount'] = $cachedList[$filter['filterhash']]['currentspotcount'];
			} else {
				# Apparently a totally new filter
				$filter['lastupdate'] = 0;
				$filter['lastvisitspotcount'] = 0;
				$filter['currentspotcount'] = 0;
			} # else

			/*
			 * Now we have to simulate a search. Because we want to 
			 * utilize existing infrastructure, we convert the filter to
			 * a format which can be used in this system
			 */
			$strFilter = '&amp;search[tree]=' . $filter['tree'];

			$valueArray = explode('&', $filter['valuelist']);
			if (!empty($valueArray)) {
				foreach($valueArray as $value) {
					$strFilter .= '&amp;search[value][]=' . $value;
				} # foreach
			} # if

			/*
			 * Now we will artifficially add the 'stamp' column to the
			 * list of parameters. Basically this tells the query
			 * system to only query for spots newer than the last
			 * update of the filter
			 */
			$strFilter .= '&amp;search[value][]=stamp:>:' . $filter['lastupdate'];
			
			# Now parse it to an array as we would get when called from a webpage
			parse_str(html_entity_decode($strFilter), $query_params);

			/*
			 * Create a fake session
			 */
			$userSession = array();
			$userSession['user'] = array('lastread' => $filter['lastupdate']);
			$userSession['user']['prefs'] = array('auto_markasread' => false);
			
			/*
			 * And convert the parsed system to an SQL statement and actually run it
			 */
			$parsedSearch = $this->filterToQuery($query_params['search'], array(), $userSession, array());
			$spotCount = $this->_db->getSpotCount($parsedSearch);

			/*
			 * Because we only ask for new spots, just increase the current
			 * amount of spots. This has a slight chance of sometimes missing
			 * a spot but it's sufficiently accurate for this kind of importance
			 */
			$filter['currentspotcount'] += $spotCount;
			
			$this->_db->setCachedFilterCount(-1, array($filter['filterhash'] => $filter));

			/*
			 * Now determine the users wich actually have this filter
			 */
			$usersWithThisFilter = $this->_db->getUsersForFilter($filter['tree'], $filter['valuelist']);
			foreach($usersWithThisFilter as $thisFilter) {
				$statisticsUpdate[$thisFilter['userid']][] = array('title' => $thisFilter['title'],
				  											   'newcount' => $spotCount,
				  											   'enablenotify' => $thisFilter['enablenotify']);
			} # foreach
		} # foreach

		/*
		 * We want to make sure all filtercounts are available for all
		 * users, hence we make sure all these records do exist
		 */
		$this->_db->createFilterCountsForEveryone();

		return $statisticsUpdate;
	} # cacheNewSpotCount
	
	/* 
	 * Geef de NZB file terug
	 */
	function getNzb($fullSpot, $nntp) {
		SpotTiming::start(__FUNCTION__);

		if ($this->_activeRetriever && $this->_cache->isCached($fullSpot['messageid'], SpotCache::SpotNzb)) {
			$nzb = true;
		} elseif ($nzb = $this->_cache->getCache($fullSpot['messageid'], SpotCache::SpotNzb)) {
			$this->_cache->updateCacheStamp($fullSpot['messageid'], SpotCache::SpotNzb);
			$nzb = $nzb['content'];
		} else {
			$nzb = $nntp->getNzb($fullSpot['nzb']);
			$this->_cache->saveCache($fullSpot['messageid'], SpotCache::SpotNzb, false, $nzb);
		} # else

		SpotTiming::stop(__FUNCTION__, array($fullSpot, $nntp));

		return $nzb;
	} # getNzb
	
	/* 
	 * Geef de image file terug
	 */
	function getImage($fullSpot, $nntp) {
		SpotTiming::start(__FUNCTION__);
		$return_code = false;

		if (is_array($fullSpot['image'])) {
			if ($this->_activeRetriever && $this->_cache->isCached($fullSpot['messageid'], SpotCache::SpotNzb)) {
				$data = true;
			} elseif ($data = $this->_cache->getCache($fullSpot['messageid'], SpotCache::SpotImage)) {
				$this->_cache->updateCacheStamp($fullSpot['messageid'], SpotCache::SpotImage);
			} else {
				try {
					$img = $nntp->getImage($fullSpot);

					if ($data = $this->_spotImage->getImageInfoFromString($img)) {
						$this->_cache->saveCache($fullSpot['messageid'], SpotCache::SpotImage, $data['metadata'], $data['content']);
					} # if	
				}
				catch(ParseSpotXmlException $x) {
					$return_code = 900;
				}
				catch(Exception $x) {
					# "No such article" error
					if ($x->getCode() == 430) {
						$return_code = 430;
					} 
					# als de XML niet te parsen is, niets aan te doen
					elseif ($x->getMessage() == 'String could not be parsed as XML') {
						$return_code = 900;
					} else {
						throw $x;
					} # else
				} # catch
			} # if
		} elseif (!empty($fullSpot['image'])) {
			list($return_code, $data) = $this->getFromWeb($fullSpot['image'], false, 24*60*60);
		} # else

		# bij een error toch een image serveren
		if (!$this->_activeRetriever) {
			if ($return_code && $return_code != 200 && $return_code != 304) {
				$data = $this->_spotImage->createErrorImage($return_code);
			} elseif (empty($fullSpot['image'])) {
				$data = $this->_spotImage->createErrorImage(901);
			} elseif ($return_code && !$data['metadata']) {
				$data = $this->_spotImage->createErrorImage($return_code);
			} elseif ($return_code && !$data) {
				$data = $this->_spotImage->createErrorImage($return_code);
			} elseif (!$data) {
				$data = $this->_spotImage->createErrorImage(999);
			} # elseif
		} elseif (!isset($data)) {
			$data = false;
		} # elseif

		SpotTiming::stop(__FUNCTION__, array($fullSpot, $nntp));
		return $data;
	} # getImage

	/* 
	 * Geef een statistics image file terug
	 */
	function getStatisticsImage($graph, $limit, $nntp, $language) {
		SpotTiming::start(__FUNCTION__);
		$spotStatistics = new SpotStatistics($this->_db);

		if (!array_key_exists($graph, $this->_spotImage->getValidStatisticsGraphs()) || !array_key_exists($limit, $this->_spotImage->getValidStatisticsLimits())) {
			$data = $this->_spotImage->createErrorImage(400);
			SpotTiming::stop(__FUNCTION__, array($graph, $limit, $nntp));
			return $data;
		} # if

		$lastUpdate = $this->_db->getLastUpdate($nntp['host']);
		$resourceid = $spotStatistics->getResourceid($graph, $limit, $language);
		$data = $this->_cache->getCache($resourceid, SpotCache::Statistics);
		if (!$data || $this->_activeRetriever || (!$this->_settings->get('prepare_statistics') && (int) $data['stamp'] < $lastUpdate)) {
			$data = $this->_spotImage->createStatistics($graph, $limit, $lastUpdate, $language);
			$this->_cache->saveCache($resourceid, SpotCache::Statistics, $data['metadata'], $data['content']);
		} # if

		$data['expire'] = true;
		SpotTiming::stop(__FUNCTION__, array($graph, $limit, $nntp));
		return $data;
	} # getStatisticsImage

	/*
	 * Geeft een Spotnet avatar image terug
	 */
	function getAvatarImage($md5, $size, $default, $rating) {
		SpotTiming::start(__FUNCTION__);
		$url = 'http://www.gravatar.com/avatar/' . $md5 . "?s=" . $size . "&d=" . $default . "&r=" . $rating;

		list($return_code, $data) = $this->getFromWeb($url, true, 60*60);
		$data['expire'] = true;
		SpotTiming::stop(__FUNCTION__, array($md5, $size, $default, $rating));
		return $data;
	} # getAvatarImage

	/* 
	 * Haalt een url op en cached deze
	 */
	function getFromWeb($url, $storeWhenRedirected, $ttl=900) {
		SpotTiming::start(__FUNCTION__);
		$url_md5 = md5($url);

		if ($this->_activeRetriever && $this->_cache->isCached($url_md5, SpotCache::Web)) {
			return array(200, true);
		} # if

		$content = $this->_cache->getCache($url_md5, SpotCache::Web);
		if (!$content || time()-(int) $content['stamp'] > $ttl) {
			$data = array();

			SpotTiming::start(__FUNCTION__ . ':curl');
			$ch = curl_init();
			curl_setopt ($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:8.0) Gecko/20100101 Firefox/8.0');
			curl_setopt ($ch, CURLOPT_URL, $url);
			curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt ($ch, CURLOPT_TIMEOUT, 15);
			curl_setopt ($ch, CURLOPT_FAILONERROR, 1);
			curl_setopt ($ch, CURLOPT_HEADER, 1);
			curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, true);
			if ($content) {
				curl_setopt($ch, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
				curl_setopt($ch, CURLOPT_TIMEVALUE, (int) $content['stamp']);
			} # if
			$response = curl_exec($ch);
			SpotTiming::stop(__FUNCTION__ . ':curl', array($response));

			/*
			 * Curl returns false on some unspecified errors (eg: a timeout)
			 */
			if ($response !== false) {
				$info = curl_getinfo($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$data['content'] = ($http_code == 304) ? $content['content'] : substr($response, -$info['download_content_length']);
			} else {
				$http_code = 700; # Curl returned an error
			} # else
			curl_close($ch);

			if ($http_code != 200 && $http_code != 304) {
				return array($http_code, false);
			} elseif ($ttl > 0) {
				if ($imageData = $this->_spotImage->getImageInfoFromString($data['content'])) {
					$data['metadata'] = $imageData['metadata'];
				} else {
					$data['metadata'] = '';
				} # else

				switch($http_code) {
					case 304:	if (!$this->_activeRetriever) {
									$this->_cache->updateCacheStamp($url_md5, SpotCache::Web);
								} # if
								break;
					default:	if ($info['redirect_count'] == 0 || ($info['redirect_count'] > 0 && $storeWhenRedirected)) {
									$this->_cache->saveCache($url_md5, SpotCache::Web, $data['metadata'], $data['content']);
								} # if
				} # switch
			} # else
		} else {
			$http_code = 304;
			$data = $content;
		} # else

		SpotTiming::stop(__FUNCTION__, array($url, $storeWhenRedirected, $ttl));

		return array($http_code, $data);
	} # getUrl

	/*
	 * Laad de spots van af positie $start, maximaal $limit spots.
	 *
	 * $parsedSearch is een array met velden, filters en sorteringen die 
	 * alles bevat waarmee SpotWeb kan filteren. 
	 */
	function loadSpots($ourUserId, $start, $limit, $parsedSearch) {
		SpotTiming::start(__FUNCTION__);
		
		# en haal de daadwerkelijke spots op
		$spotResults = $this->_db->getSpots($ourUserId, $start, $limit, $parsedSearch, false);

		$spotCnt = count($spotResults['list']);
		for ($i = 0; $i < $spotCnt; $i++) {
			# We forceren category naar een integer, sqlite kan namelijk een lege
			# string terug ipv een category nummer
			$spotResults['list'][$i]['category'] = (int) $spotResults['list'][$i]['category'];
			
			# We trekken de lijst van subcategorieen uitelkaar 
			$spotResults['list'][$i]['subcatlist'] = explode("|", 
							$spotResults['list'][$i]['subcata'] . 
							$spotResults['list'][$i]['subcatb'] . 
							$spotResults['list'][$i]['subcatc'] . 
							$spotResults['list'][$i]['subcatd'] . 
							$spotResults['list'][$i]['subcatz']);
		} # foreach

		SpotTiming::stop(__FUNCTION__, array($spotResults));
		return $spotResults;
	} # loadSpots()

	/*
	 * Bereid een string met daarin categorieen voor en 'expand' 
	 * die naar een complete string met alle subcategorieen daarin
	 */
	public function prepareCategorySelection($dynaList) {
		$strongNotList = array();
		$categoryList = array();
		
		# 
		# De Dynatree jquery widget die we gebruiken haalt zijn data uit ?page=catsjson,
		# voor elke node in de boom geven wij een key mee.
		# Stel je de boom als volgt voor, met tussen haakjes de unieke key:
		#
		# - Beeld (cat0)
		# +-- Film (cat0_z0)
		# +--- Formaat (cat0_z0_a)
		# +----- DivX (cat0_z0_a0)
		# +----- WMV (cat0_z0_a1)
		# +-- Series (cat0_z1)
		# +--- Formaat (cat0_z1_a)
		# +----- DivX (cat0_z1_a0)
		# +----- WMV (cat0_z1_a1)
		# +--- Bron (cat0_z1_b)
		# - Applicaties (cat3)
		# +-- Formaat (cat1_zz_a / cat1_a)
		# 
		# Oftewel - je hebt een hoofdcategory nummer, daaronder heb je een type, daaronder 
		# een subcategory type (a,b,c etc), en daaronder heb je dan weer een nummer welke subcategory het is.
		#
		# Als je in bovenstaand voorbeeld dus Film in DivX wilt selecteren, dan is de keywaarde simpelweg cat0_z0_a0, 
		# wil je echter heel 'Beeld' selecteren dan is 'cat0' al genoeg. Als je echter in de Dynatree boom
		# zelf het item 'Beeld' zou selecteren, dan zal Dynatree enkel de parentitem doorsturen, dus cat0_z0
		#
		# Als we gebruikers handmatig de category willen laten opgeven (bv. door een entry in settings.php)
		# dan is het bijzonder onhandig als ze al die categorieen individueel moeten opgeven. Om dit op te
		# lossen hebben we een aantal shorthands toegevoegd aan de filter taal welke dan door Spotweb zelf
		# weer 'uitgepakt' worden naar een volledige zoekopdracht.
		#
		# In een 'settings-zoekopdracht' zijn de volgende shortcuts toegestaan voor het automatisch uitvouwen van
		# de boom:
		#
		# cat0						- Zal uitgebreid worden naar alle subcategorieen van category 0
		# cat0_z0_a					- Zal uitgebreid worden naar alle subcategorieen 'A' van category 0, type z0.
		# !cat0_z0_a1				- Zal cat0_z0_a1 verwijderen uit de lijst (volgorde van opgeven is belangrijk)
		# ~cat0_z0_a1				- 'Verbied' dat een spot in cat0_z0_a1 zit
		# cat0_a					- Alles in a voor hoofdcategorie 0 kiezen
		#
		$newTreeQuery = '';
		
		# We lopen nu door elk item in de lijst heen, en expanden die eventueel naar
		# een volledige category met subcategorieen indien nodig.
		$dynaListCount = count($dynaList);
		for($i = 0; $i < $dynaListCount; $i++) {
			# De opgegeven category kan in drie soorten voorkomen:
			#     cat1_z0_a			==> Alles van cat1, type z0, en daar alles van 'a' selecteren
			#     cat1_z0			==> Alles van cat1, type z0
			# 	  cat1_a			==> Alles van cat1, alles van 'a' selecteren
			#	  cat1				==> Heel cat1 selecteren
			#
			# Omdat we in deze code de dynatree emuleren, voeren we deze lelijke hack uit.
			if ((strlen($dynaList[$i]) > 0) && ($dynaList[$i][0] == 'c')) {
				$hCat = (int) substr($dynaList[$i], 3, 1);
				
				# was een type + subcategory gespecificeerd? (cat1_z0_a)
				if (strlen($dynaList[$i]) == 9) {
					$typeSelected = substr($dynaList[$i], 5, 2);
					$subCatSelected = substr($dynaList[$i], 8);
				# was enkel een category gespecificeerd? (cat1)
				} elseif (strlen($dynaList[$i]) == 4) {
					$typeSelected = '*';
					$subCatSelected = '*';
				# was een category en type gespecificeerd? (cat1_z0)
				} elseif ((strlen($dynaList[$i]) == 7) && ($dynaList[$i][5] === 'z')) {
					$typeSelected = substr($dynaList[$i], 5, 2);
					$subCatSelected = '*';
				# was een category en subcategorie gespecificeerd, oude style? (cat1_a3)
				} elseif (((strlen($dynaList[$i]) == 7) || (strlen($dynaList[$i]) == 8)) && ($dynaList[$i][5] !== 'z')) {
					# Zet die oude style om naar de verschillende expliciete categorieen
					foreach(SpotCategories::$_categories[$hCat]['z'] as $typeKey => $typeValue) {
						$newTreeQuery .= "," . substr($dynaList[$i], 0, 4) . '_z' . $typeKey . '_' . substr($dynaList[$i], 5);
					} # foreach
					
					$typeSelected = '';
					$subCatSelected = '';
				# was een subcategory gespecificeerd? (cat1_a)
				} elseif (strlen($dynaList[$i]) == 6) {
					$typeSelected = '*';
					$subCatSelected = substr($dynaList[$i], 5, 1);
				} else {
					$newTreeQuery .= "," . $dynaList[$i];
					
					$typeSelected = '';
					$subCatSelected = '';
				} # else

				#
				# creeer een string die alle subcategories bevat
				#
				# we loopen altijd door alle subcategorieen heen zodat we zowel voor complete category selectie
				# als voor enkel subcategory selectie dezelfde code kunnen gebruiken.
				#
				$tmpStr = '';
				foreach(SpotCategories::$_categories[$hCat] as $subCat => $subcatValues) {
				
					/*
					 * We kunnen vier gevallen hebben:
					 *
					 *  $subCatSelected bevat een lege string, dan matched het op niets
					 * 	$subCatSelected bevat een sterretje, dan matchen we alle subcategorieen
					 *	$typeSelected bevat een lege string, dan matched het op niets
					 *  $typeSelected bevat een sterretje, dan matched het op alle subcategorieen
					 */
					if ($subCatSelected == '*') {
						foreach(SpotCategories::$_categories[$hCat]['z'] as $typeKey => $typeValue) {
							$typeKey = 'z' . $typeKey;
							if (($typeKey == $typeSelected) || ($typeSelected == '*')) {
								$tmpStr .= ',sub' . $hCat . '_' . $typeKey;
							} # if
						} # foreach
					} elseif (($subCat == $subCatSelected) && ($subCat !== 'z')) {
						foreach(SpotCategories::$_categories[$hCat]['z'] as $typeKey => $typeValue) {
							$typeKey = 'z' . $typeKey;
							if (($typeKey == $typeSelected) || ($typeSelected == '*')) {
							
								foreach(SpotCategories::$_categories[$hCat][$subCat] as $x => $y) {
									if (in_array($typeKey, $y[2])) {
										$tmpStr .= ",cat" . $hCat . "_" . $typeKey . '_' . $subCat . $x;
									} # if
								} # foreach
							} # if
						} # foreach
					} # if
				} # foreach

				$newTreeQuery .= $tmpStr;
			} elseif (substr($dynaList[$i], 0, 1) == '!') {
				# als het een NOT is, haal hem dan uit de lijst
				$newTreeQuery = str_replace(',' . substr($dynaList[$i], 1), "", $newTreeQuery);
			} elseif (substr($dynaList[$i], 0, 1) == '~') {
				# als het een STRONG NOT is, zorg dat hij in de lijst blijft omdat we die moeten
				# meegeven aan de nextpage urls en dergelijke.
				$newTreeQuery .= "," . $dynaList[$i];
				
				# en voeg hem toe aan een strong NOT list (~cat0_z0_d12)
				$strongNotTmp = explode("_", $dynaList[$i], 2);

				/* To deny a whole category, we have to take an other shortcut */
				if (count($strongNotTmp) == 1) {
					$strongNotList[(int) substr($strongNotTmp[0], 4)][] = '';
				} else {
					$strongNotList[(int) substr($strongNotTmp[0], 4)][] = $strongNotTmp[1];
				} # else
			} else {
				$newTreeQuery .= "," . $dynaList[$i];
			} # else
		} # for
		if ((!empty($newTreeQuery)) && ($newTreeQuery[0] == ",")) { 
			$newTreeQuery = substr($newTreeQuery, 1); 
		} # if

		#
		# Vanaf hier hebben we de geprepareerde lijst - oftewel de lijst met categorieen 
		# die al helemaal in het formaat is zoals Dynatree hem ons ook zou aanleveren.
		# 
		# We vertalen de string met subcategorieen hier netjes naar een array met alle
		# individuele subcategorieen zodat we die later naar SQL kunnen omzetten.
		$dynaList = explode(',', $newTreeQuery);

		foreach($dynaList as $val) {
			if (substr($val, 0, 3) == 'cat') {
				# 0e element is hoofdcategory
				# 1e element is type
				# 2e element is category
				$val = explode('_', (substr($val, 3) . '_'));

				$catVal = $val[0];
				$typeVal = $val[1];
				$subCatIdx = substr($val[2], 0, 1);
				$subCatVal = substr($val[2], 1);

				if (count($val) >= 4) {
					$categoryList['cat'][$catVal][$typeVal][$subCatIdx][] = $subCatVal;
				} # if
			} elseif (substr($val, 0, 3) == 'sub') {
				# 0e element is hoofdcategory
				# 1e element is type
				$val = explode('_', (substr($val, 3) . '_'));

				$catVal = $val[0];
				$typeVal = $val[1];

				# Creer de z-category in de categorylist
				if (count($val) == 3) {
					if (!isset($categoryList['cat'][$catVal][$typeVal])) {
						$categoryList['cat'][$catVal][$typeVal] = array();
					} # if
				} # if
			} # elseif
		} # foreach
		
		return array($categoryList, $strongNotList);
	} # prepareCategorySelection

	/*
	 * Converteert een lijst met subcategorieen 
	 * naar een lijst met daarin SQL where filters
	 */
	private function categoryListToSql($categoryList) {
		$categorySql = array();

		# controleer of de lijst geldig is
		if ((!isset($categoryList['cat'])) || (!is_array($categoryList['cat']))) {
			return $categorySql;
		} # if

		# 
		# We vertalen nu de lijst met sub en hoofdcategorieen naar een SQL WHERE statement, we 
		# doen dit in twee stappen waarbij de uiteindelijke category filter een groot filter is.
		# 
		#
		# Testset met filters:
		#   cat0_z0_a9,cat0_z1_a9,cat0_z3_a9, ==> HD beeld
		#	cat0_z0_a9,cat0_z0_b3,cat0_z0_c1,cat0_z0_c2,cat0_z0_c6,cat0_z0_c11,~cat0_z1,~cat0_z2,~cat0_z3 ==> Nederlands ondertitelde films
		# 	cat0_a9 ==> Alles in x264HD
		#	cat1_z0,cat1_z1,cat1_z2,cat1_z3 ==> Alle muziek, maar soms heeft muziek geen genre ingevuld!
		#
		# De categoryList array is als volgt opgebouwd:
		#
		#	array(1) {
		#	  ["cat"]=>
		#	  array(1) {								
		#		[1]=>									<== Hoofdcategory nummer (cat1)
		#		array(4) {
		#		  ["z0"]=>								<== Type (subcatz) nummer (cat1_z0)
		#		  array(4) {
		#			["a"]=>								<== Subcategorieen (cat1_z0_a)
		#			array(9) {
		#			  [0]=>								
		#			  string(1) "0"						<== Geselecteerde subcategory (in totaal dus cat1_z0_a0)
		#			}
		#			["b"]=>
		#			array(7) {
		#			  [0]=>
		#			  string(1) "0"
		#
		#
		foreach($categoryList['cat'] as $catid => $cat) {
			#
			# Voor welke category die we hebben, gaan we alle subcategorieen 
			# af en proberen die vervolgens te verwerken.
			#
			if ((is_array($cat)) && (!empty($cat))) {
				#
				# Uiteraard is een LIKE query voor category search niet super schaalbaar
				# maar het is in de praktijk een hele performante manier
				#
				foreach($cat as $type => $typeValues) {
					$catid = (int) $catid;
					$tmpStr = "((s.category = " . (int) $catid . ")";
					
					# dont filter the zz types (games/apps)
					if ($type[1] !== 'z') {
						$tmpStr .= " AND (s.subcatz = '" . $type . "|')";
					} # if

					$subcatItems = array();
					foreach($typeValues as $subcat => $subcatItem) {
						$subcatValues = array();
						
						foreach($subcatItem as $subcatValue) {
							#
							# een spot heeft maar 1 a en z subcat, dus dan kunnen we gewoon
							# equality ipv like doen
							#
							if ($subcat == 'a')  {
								$subcatValues[] = "(s.subcata = '" . $subcat . $subcatValue . "|') ";
							} elseif (in_array($subcat, array('b', 'c', 'd'))) {
								$subcatValues[] = "(s.subcat" . $subcat . " LIKE '%" . $subcat . $subcatValue . "|%') ";
							} # if
						} # foreach

						# 
						# We voegen alle subcategorieen items binnen dezelfde subcategory en binnen dezelfde category
						# (bv. alle formaten films) samen met een OR. Dus je kan kiezen voor DivX en WMV als formaat.
						#
						if (count($subcatValues) > 0) {
							$subcatItems[] = " (" . join(" OR ", $subcatValues) . ") ";
						} # if
					} # foreach subcat

					#
					# Hierna voegen we binnen de hoofdcategory and type (Beeld + Film, Geluid), de subcategorieen filters die hierboven
					# zijn samengesteld weer samen met een AND, bv. genre: actie, type: divx.
					#
					# Je krijgt dus een filter als volgt:
					#
					# (((category = 0) AND ( ((subcata = 'a0|') ) AND ((subcatd LIKE '%d0|%')
					# 
					# Dit zorgt er voor dat je wel kan kiezen voor meerdere genres, maar dat je niet bv. een Linux actie game
					# krijgt (ondanks dat je Windows filterde) alleen maar omdat het een actie game is waar je toevallig ook
					# op filterde.
					#
					if (count($subcatItems) > 0) {
						$tmpStr .= " AND (" . join(" AND ", $subcatItems) . ") ";
					} # if
					
					# Sluit het haakje af
					$tmpStr .= ")";
					$categorySql[] = $tmpStr;
				} # foreach type

			} # if
		} # foreach

		return $categorySql;
	} # categoryListToSql 
	
	/*
	 * Zet een lijst met "strong nots" om naar de daarbij
	 * behorende SQL where statements
	 */
	private function strongNotListToSql($strongNotList) {
		$strongNotSql = array();
		
		if (empty($strongNotList)) {
			return array();
		} # if

		#
		# Voor elke strong not die we te zien krijgen, creer de daarbij
		# behorende SQL WHERE filter
		#
		foreach(array_keys($strongNotList) as $strongNotCat) {
			foreach($strongNotList[$strongNotCat] as $strongNotSubcat) {
				/*
				 * When the strongnot is for a whole category (eg: cat0), we can
				 * make the NOT even simpler
				 */
				if (empty($strongNotSubcat)) {
					$strongNotSql[] = "(NOT (s.Category = " . (int) $strongNotCat . "))";
				} else {
					$subcats = explode('_', $strongNotSubcat);

					# category a en z mogen maar 1 keer voorkomen, dus dan kunnen we gewoon
					# equality ipv like doen
					if (count($subcats) == 1) {
						if (in_array($subcats[0][0], array('a', 'z'))) { 
							$strongNotSql[] = "(NOT ((s.Category = " . (int) $strongNotCat . ") AND (s.subcat" . $subcats[0][0] . " = '" . $this->_db->safe($subcats[0]) . "|')))";
						} elseif (in_array($subcats[0][0], array('b', 'c', 'd'))) { 
							$strongNotSql[] = "(NOT ((s.Category = " . (int) $strongNotCat . ") AND (s.subcat" . $subcats[0][0] . " LIKE '%" . $this->_db->safe($subcats[0]) . "|%')))";
						} # if
					} elseif (count($subcats) == 2) {
						if (in_array($subcats[1][0], array('a', 'z'))) { 
							$strongNotSql[] = "(NOT ((s.Category = " . (int) $strongNotCat . ") AND (s.subcatz = '" . $subcats[0] . "|') AND (subcat" . $subcats[1][0] . " = '" . $this->_db->safe($subcats[1]) . "|')))";
						} elseif (in_array($subcats[1][0], array('b', 'c', 'd'))) { 
							$strongNotSql[] = "(NOT ((s.Category = " . (int) $strongNotCat . ") AND (s.subcatz = '" . $subcats[0] . "|') AND (subcat" . $subcats[1][0] . " LIKE '%" . $this->_db->safe($subcats[1]) . "|%')))";
						} # if
					} # else
				} # else not whole subcat
			} # foreach				
		} # forEach

		return $strongNotSql;
	} # strongNotListToSql

	/*
	 * Prepareert de filter values naar een altijd juist formaat 
	 */
	private function prepareFilterValues($search) {
		$filterValueList = array();
		
		# We hebben drie soorten filters:
		#		- Oude type waarin je een search[type] hebt met als waarden stamp,titel,tag etc en search[text] met 
		#		  de waarde waar je op wilt zoeken. Dit beperkt je tot maximaal 1 type filter wat het lastig maakt.
		#
		# 		  We converteren deze oude type zoekopdrachten automatisch naar het nieuwe type.
		#
		#		- Nieuw type waarin je een search[value] array hebt, hierin zitten values in de vorm: type:operator:value, dus
		#		  bijvoorbeeld tag:=:spotweb. Er is ook een shorthand beschikbaar, als je de operator weglaat (dus: tag:spotweb),
		#		  nemen we aan dat de EQ operator bedoelt is.
		#
		#		- Speciale soorten lijsten - er zijn een aantal types welke een speciale betekenis hebben:
		#				New:0 					(nieuwe posts)
		#				Downloaded:0 			(spots welke gedownload zijn door deze account)
		#				Watch:0 				(spots die op de watchlist staan van deze account)
		#				Seen:0 					(spots die al geopend zijn door deze account)
		#				MyPostedSpots:0 		(spots die gepost zijn door die user)
		#				WhitelistedSpotters:0   (spots posted by a whitelisted spotter)
		#				
		#
		if (isset($search['type'])) {
			if (!isset($search['text'])) {
				$search['text'] = '';
			} # if
			
			# Een combinatie van oude filters en nieuwe kan voorkomen, we 
			# willen dan niet met deze conversie de normaal soorten filters
			# overschrijven.
			if ((!isset($search['value'])) || (!is_array($search['value']))) {
				$search['value'] = array();
			} # if
			$search['value'][] = $search['type'] . ':=:' . $search['text'];
			unset($search['type']);
		} # if

		# Zorg er voor dat we altijd een array hebben waar we door kunnen lopen
		if ((!isset($search['value'])) || (!is_array($search['value']))) {
			$search['value'] = array();
		} # if

		# en we converteren het nieuwe type (field:operator:value) naar een array zodat we er makkelijk door kunnen lopen
		foreach($search['value'] as $value) {
			if (!empty($value)) {
				$tmpFilter = explode(':', $value);
				
				# als er geen comparison operator is opgegeven, dan
				# betekent dat een '=' operator, dus fix de array op
				# die manier.
				if (count($tmpFilter) < 3) {
					$tmpFilter = array($tmpFilter[0],
									   '=',
									   $tmpFilter[1]);
				} # if
				
				# maak de daadwerkelijke filter
				$filterValueTemp = Array('fieldname' => $tmpFilter[0],
										 'operator' => $tmpFilter[1],
										 'value' => join(":", array_slice($tmpFilter, 2)));
										 
				# en creeer een filtervaluelist, we checken eeerst
				# of een gelijkaardig item niet al voorkomt in de lijst
				# met filters - als je namelijk twee keer dezelfde filter
				# toevoegt wil MySQL wel eens onverklaarbaar traag worden
				if (!in_array($filterValueTemp, $filterValueList)) {
					$filterValueList[] = $filterValueTemp;
				} # if
			} # if
		} # for
		
		return $filterValueList;
	} # prepareFilterValues
	
	/*
	 * Converteert meerdere user opgegeven 'text' filters naar SQL statements
	 */
	private function filterValuesToSql($filterValueList, $currentSession) {
		# Add a list of possible text searches
		$filterValueSql = array('OR' => array(), 'AND' => array());
		$additionalFields = array();
		$additionalTables = array();
		$additionalJoins = array();
		
		$sortFields = array();
		$textSearchFields = array();
		
		# Een lookup tabel die de zoeknaam omzet naar een database veldnaam
		$filterFieldMapping = array('filesize' => 's.filesize',
								  'date' => 's.stamp',
								  'stamp' => 's.stamp',
								  'userid' => 's.spotterid',
								  'spotterid' => 's.spotterid',
								  'moderated' => 's.moderated',
								  'poster' => 's.poster',
								  'titel' => 's.title',
								  'tag' => 's.tag',
								  'new' => 'new',
								  'reportcount' => 's.reportcount',
								  'commentcount' => 's.commentcount',
								  'downloaded' => 'downloaded', 
								  'mypostedspots' => 'mypostedspots',
								  'whitelistedspotters' => 'whitelistedspotters',
								  'watch' => 'watch', 
								  'seen' => 'seen');

		foreach($filterValueList as $filterRecord) {
			$tmpFilterFieldname = strtolower($filterRecord['fieldname']);
			$tmpFilterOperator = $filterRecord['operator'];
			$tmpFilterValue = $filterRecord['value'];

			# We proberen nu het opgegeven veldnaam te mappen naar de database
			# kolomnaam. Als dat niet kan, gaan we er van uit dat het een 
			# ongeldige zoekopdracht is, en dan interesseert ons heel de zoek
			# opdracht niet meer.
			if (!isset($filterFieldMapping[$tmpFilterFieldname])) {
				break;
			} # if

			# valideer eerst de operatoren
			if (!in_array($tmpFilterOperator, array('>', '<', '>=', '<=', '=', '!='))) {
				break;
			} # if

			# een lege zoekopdracht negeren we gewoon, 'empty' kunnen we niet
			# gebruiken omdat empty(0) ook true geeft, en 0 is wel een waarde
			# die we willen testen
			if (strlen($tmpFilterValue) == 0) {
				continue;
			} # if

			#
			# als het een pure textsearch is, die we potentieel kunnen optimaliseren,
			# met een fulltext search (engine), voer dan dit pad uit zodat we de 
			# winst er mee nemen.
			#
			if (in_array($tmpFilterFieldname, array('tag', 'poster', 'titel'))) {
				#
				# Sommige databases (sqlite bv.) willen al hun fulltext searches in een
				# function aanroep. We zoeken hier dus alle fulltext searchable velden samen
				# en creeeren de textfilter later in 1 keer.
				#
				if (!isset($textSearchFields[$filterFieldMapping[$tmpFilterFieldname]])) {
					$textSearchFields[$filterFieldMapping[$tmpFilterFieldname]] = array();
				} # if
				$textSearchFields[$filterFieldMapping[$tmpFilterFieldname]][] = array('fieldname' => $filterFieldMapping[$tmpFilterFieldname], 'value' => $tmpFilterValue);
			} elseif (in_array($tmpFilterFieldname, array('new', 'downloaded', 'watch', 'seen', 'mypostedspots', 'whitelistedspotters'))) {
				# 
				# Er zijn speciale veldnamen welke we gebruiken als dummies om te matchen 
				# met de spotstatelist. Deze veldnamen behandelen we hier
				#
				switch($tmpFilterFieldname) {
					case 'new' : {
							$tmpFilterValue = ' ((s.stamp > ' . (int) $this->_db->safe($currentSession['user']['lastread']) . ')';
							$tmpFilterValue .= ' AND (l.seen IS NULL))';
							
							break;
					} # case 'new' 

					case 'whitelistedspotters' : {
						$tmpFilterValue = ' (wl.spotterid IS NOT NULL)';

						break;
					} # case 'whitelistedspotters'
					
					case 'mypostedspots' : {
						$additionalJoins[] = array('tablename' => 'spotsposted',
												   'tablealias' => 'spost',
												   'jointype' => 'LEFT',
												   'joincondition' => 'spost.messageid = s.messageid');
						$tmpFilterValue = ' (spost.ouruserid = ' . (int) $this->_db->safe($currentSession['user']['userid']) . ') '; 	
						$sortFields[] = array('field' => 'spost.stamp',
											  'direction' => 'DESC',
											  'autoadded' => true,
											  'friendlyname' => null);
						break;
					} # case 'mypostedspots'

					case 'downloaded' : { 
						$tmpFilterValue = ' (l.download IS NOT NULL)'; 	
						$sortFields[] = array('field' => 'downloadstamp',
											  'direction' => 'DESC',
											  'autoadded' => true,
											  'friendlyname' => null);
						break;
					} # case 'downloaded'
					case 'watch' 	  : { 
						$tmpFilterValue = ' (l.watch IS NOT NULL)'; break;
						$sortFields[] = array('field' => 'watchstamp',
											  'direction' => 'DESC',
											  'autoadded' => true,
											  'friendlyname' => null);
					} # case 'watch'
					case 'seen' 	  : {
						$tmpFilterValue = ' (l.seen IS NOT NULL)'; 	break;
						$sortFields[] = array('field' => 'seenstamp',
											  'direction' => 'DESC',
											  'autoadded' => true,
											  'friendlyname' => null);
					} # case 'seen'
				} # switch
				
				# en creeer de query string
				$filterValueSql['AND'][] = $tmpFilterValue;
			} else {
				# Anders is het geen textsearch maar een vergelijkings operator, 
				# eerst willen we de vergelijking eruit halen.
				#
				# De filters komen in de vorm: Veldnaam:Operator:Waarde, bv: 
				#   filesize:>=:4000000
				#
				if ($tmpFilterFieldname == 'date') {
					$tmpFilterValue = date("U",  strtotime($tmpFilterValue));
				} elseif ($tmpFilterFieldname == 'stamp') {
					$tmpFilterValue = (int) $tmpFilterValue;
				} elseif (($tmpFilterFieldname == 'filesize') && (is_numeric($tmpFilterValue) === false)) {
					# We casten expliciet naar float om een afrondings bug in PHP op het 32-bits
					# platform te omzeilen.
					$val = (float) trim(substr($tmpFilterValue, 0, -1));
					$last = strtolower($tmpFilterValue[strlen($tmpFilterValue) - 1]);
					switch($last) {
						case 'g': $val *= (float) 1024;
						case 'm': $val *= (float) 1024;
						case 'k': $val *= (float) 1024;
					} # switch
					$tmpFilterValue = round($val, 0);
				} # if
					
				# als het niet numeriek is, zet er dan een quote by
				if (!is_numeric($tmpFilterValue)) {
					$tmpFilterValue = "'" . $this->_db->safe($tmpFilterValue) . "'";
				} else {
					$tmpFilterValue = $this->_db->safe($tmpFilterValue);
				} # if

				# en creeer de query string
				if (in_array($tmpFilterFieldname, array('spotterid', 'userid'))) {
					$filterValueSql['OR'][] = ' (' . $filterFieldMapping[$tmpFilterFieldname] . ' ' . $tmpFilterOperator . ' '  . $tmpFilterValue . ') ';
				} else {
					$filterValueSql['AND'][] = ' (' . $filterFieldMapping[$tmpFilterFieldname] . ' ' . $tmpFilterOperator . ' '  . $tmpFilterValue . ') ';
				} # else
			} # if
		} # foreach

		# 
		# Nu controleren we of we een of meer $textSearchFields hebben waarop we 
		# eventueel een fulltext search zouden kunnen loslaten. Als we die hebben
		# vragen we aan de specifiek database engine om deze zoekopdracht te 
		# optimaliseren.
		#
		if (!empty($textSearchFields)) {
			foreach($textSearchFields as $searchField => $searches) {
				$parsedTextQueryResult = $this->_db->createTextQuery($searches);

				if (in_array($tmpFilterFieldname, array('poster', 'tag'))) {
					$filterValueSql['AND'][] = ' (' . implode(' OR ', $parsedTextQueryResult['filterValueSql']) . ') ';
				} else {
					$filterValueSql['AND'][] = ' (' . implode(' AND ', $parsedTextQueryResult['filterValueSql']) . ') ';
				} # if

				$additionalTables = array_merge($additionalTables, $parsedTextQueryResult['additionalTables']);
				$additionalFields = array_merge($additionalFields, $parsedTextQueryResult['additionalFields']);
				$sortFields = array_merge($sortFields, $parsedTextQueryResult['sortFields']);
			} # foreach
		} # if

		
		return array($filterValueSql, $additionalFields, $additionalTables, $additionalJoins, $sortFields);
	} # filterValuesToSql

	/*
	 * Genereert de lijst met te sorteren velden
	 */
	private function prepareSortFields($sort, $sortFields) {
		$VALID_SORT_FIELDS = array('category' => 1, 
								   'poster' => 1, 
								   'title' => 1, 
								   'filesize' => 1, 
								   'stamp' => 1, 
								   'subcata' => 1, 
								   'spotrating' => 1, 
								   'commentcount' => 1);

		if ((!isset($sort['field'])) || (!isset($VALID_SORT_FIELDS[$sort['field']]))) {
			# We sorteren standaard op stamp, we voegen die sortering als laatste toe
			# zodat alle andere (eventueel expliciete) sorteringen voorrang krijgt
			$sortFields[] = array('field' => 's.stamp', 'direction' => 'DESC', 'autoadded' => true, 'friendlyname' => null);
		} else {
			if (strtoupper($sort['direction']) != 'ASC') {
				$sort['direction'] = 'DESC';
			} # if
			
			# Omdat deze sortering expliciet is opgegeven door de user, geven we deze voorrang
			# boven de automatisch toegevoegde sorteringen en zetten hem dus aan het begin
			# van de sorteer lijst.
			array_unshift($sortFields, array('field' => 's.' . $sort['field'], 
											 'direction' => $sort['direction'], 
											 'autoadded' => false, 
											 'friendlyname' => $sort['field']));
		} # else
		
		return $sortFields;
	} # prepareSortFields
	
	
	/*
	 * Comprimeert een expanded category list naar een gecomprimeerd formaat.
	 * Zie de comments bij prepareCategorySelection() voor uitleg wat dit
	 * precies betekent
	 */
	function compressCategorySelection($categoryList, $strongNotList) {
		SpotTiming::start(__FUNCTION__);
		$compressedList = '';

		#
		# Nu, we gaan feitelijk elke category die we hebben en elke subcategory daaronder
		# aflopen om te zien of alle vereiste elementen er zijn. Als die er zijn, dan unsetten we
		# die in $categoryList en voegen de compressede manier toe.
		#
		foreach(SpotCategories::$_head_categories as $headCatNumber => $headCatValue) {
			$subcatsMissing = array();

			# loop door elke subcategorie heen 
			if (isset($categoryList['cat'][$headCatNumber])) {
				$subcatsMissing[$headCatNumber] = array();

				foreach($categoryList['cat'][$headCatNumber] as $subCatType => $subCatValues) {
					$subcatsMissing[$headCatNumber][$subCatType] = array();
	
					foreach(SpotCategories::$_categories[$headCatNumber] as $subCat => $subcatValues) {
						if ($subCat !== 'z') {
							if (isset($categoryList['cat'][$headCatNumber][$subCatType][$subCat])) {
								# en loop door de subcategorie waardes heen om te zien of er daar missen
								foreach(SpotCategories::$_categories[$headCatNumber][$subCat] as $subcatValue => $subcatDescription) {
									# Make sure this subcat is available for this type
									if (in_array($subCatType, $subcatDescription[2])) {
										# Is de category item in deze hoofdcategory's subcategory beschikbaar
										if (array_search($subcatValue, $categoryList['cat'][$headCatNumber][$subCatType][$subCat]) === false) {
											$subcatsMissing[$headCatNumber][$subCatType][$subCat][$subcatValue] = 1;
										} # if
									} # if
								} # foreach
							} else {
								// $subcatsMissing[$headCatNumber][$subCatType][$subCat] = array();
							} # if
						} # if
					} # foreach
					
				} # foreach

//var_dump($categoryList);
//var_dump(expression)($subcatsMissing);
//die();

				#
				# Niet de hele hoofdgroep is geselecteerd, dan selecteren we met de hand
				# handmatig de verschillende subcategorieen.
				#
				if (!empty($subcatsMissing[$headCatNumber])) {
					# Er kunnen drie situaties zijn:
					# - de subcategorie bestaat helemaal niet, dan selecteren we heel de subcategorie.
					# - de subcategorie bestaat maar is leeg, dan willen we er niets uit hebben
					# - de subcategorie bestaat, maar is niet leeg. Dan bevat het de items die we NIET willen hebben
					foreach($categoryList['cat'][$headCatNumber] as $subType => $subTypeValue) {
						#
						# Is heel de hoofdcat+subtype (cat0_z0, cat0_z1) geselecteerd?
						#
						if (!empty($subcatsMissing[$headCatNumber][$subType])) {
							foreach(SpotCategories::$_subcat_descriptions[$headCatNumber] as $subCatKey => $subCatValue) {
								if ($subCatKey !== 'z') {
									if (!isset($subcatsMissing[$headCatNumber][$subType][$subCatKey])) {
										// $compressedList .= 'cat' . $headCatNumber . '_' . $subType . '_' . $subCatKey . ',';
									} elseif (empty($subcatsMissing[$headCatNumber][$subType][$subCatKey])) {
										# Als de subcategorie helemaal leeg is, dan wil de 
										# gebruiker er niets uit hebben
									} else {
										# De subcategorie bestaat, maar bevat enkele items die de
										# gebruiker niet wil hebben. Die pikken we er hier uit.
										#
										# Afhankelijk of de user meer dan de helft wel of niet 
										# geselecteerd heeft, voegen we hier not's toe of juist niet
										#
										$moreFalseThanTrue = (count(@$subcatsMissing[$headCatNumber][$subType][$subCatKey]) > (count(@SpotCategories::$_categories[$headCatNumber][$subCatKey][$subCatValue]) / 2));
										foreach(SpotCategories::$_categories[$headCatNumber][$subCatKey] as $subCatValue => $subCatDesc) {
											if (in_array($subType, $subCatDesc[2])) {
												if ($moreFalseThanTrue) {
													if (!isset($subcatsMissing[$headCatNumber][$subType][$subCatKey][$subCatValue])) {
														$compressedList .= 'cat' . $headCatNumber . '_' . $subType . '_' . $subCatKey . $subCatValue . ',';
													} # if
												} else {
													if (isset($subcatsMissing[$headCatNumber][$subType][$subCatKey][$subCatValue])) {
														# We moeten zeker er van zijn dat heel de categorie geselecteerd is, dus daar
														# checken we extra op
														if (strpos(',' . $compressedList . ',', ',cat' . $headCatNumber . '_' . $subType . '_' . $subCatKey . ',') === false) {
															$compressedList .= 'cat' . $headCatNumber . '_' . $subType . '_' . $subCatKey . ',';
														} # if
														
														# en 'deselecteer' nu de category
														$compressedList .= '!cat' . $headCatNumber . '_' . $subType . '_' . $subCatKey . $subCatValue . ',';
													} # if
												} # if
											} # if
										} # foreach
									} # else
								} # if
								
							} # foreach
						} else {
							$compressedList .= 'cat' . $headCatNumber . '_' . $subType . ',';
						} # if
					} # foreach
				} else {
					$compressedList .= 'cat' . $headCatNumber . ',';
				} # else
			} # if
		} # foreach

		# en voeg de strong not lijst toe
		if (!empty($strongNotList)) {
			foreach($strongNotList as $headCat => $subcatList) {
				foreach($subcatList as $subcatValue) {
					$compressedList .= '~cat' . $headCat . '_' . $subcatValue . ',';
				} # foreach
			} # foreach
		} # if

		SpotTiming::stop(__FUNCTION__, array($compressedList));

		return $compressedList;
	} # compressCategorySelection

	/*
	 * Converteer een array met search termen (tree, type en value) naar een SQL
	 * statement dat achter een WHERE geplakt kan worden.
	 */
	function filterToQuery($search, $sort, $currentSession, $indexFilter) {
		SpotTiming::start(__FUNCTION__);
		
		$isUnfiltered = false;
		
		$categoryList = array();
		$categorySql = array();
		
		$strongNotList = array();
		$strongNotSql = array();
		
		$filterValueList = array();
		$filterValueSql = array();
		
		$additionalFields = array();
		$additionalTables = array();
		$additionalJoins = array();
		$sortFields = array();
		
		# Als er geen enkele filter opgegeven is, filteren we niets
		if (empty($search)) {
			return array('filter' => '',
						 'search' => array(),
					     'additionalFields' => array(),
						 'additionalTables' => array(),
						 'additionalJoins' => array(),
						 'categoryList' => array(),
						 'strongNotList' => array(),
					     'filterValueList' => array(),
						 'unfiltered' => false,
					     'sortFields' => array(array('field' => 'stamp', 'direction' => 'DESC', 'autoadded' => true, 'friendlyname' => null)));
		} # if

		#
		# Verwerk de parameters in $search (zowel legacy parameters, als de nieuwe 
		# type filter waardes), naar een array met filter waarden
		#
		$filterValueList = $this->prepareFilterValues($search);
		list($filterValueSql, $additionalFields, $additionalTables, $additionalJoins, $sortFields) = $this->filterValuesToSql($filterValueList, $currentSession);

		# als er gevraagd om de filters te vergeten (en enkel op het woord te zoeken)
		# resetten we gewoon de boom
		if ((isset($search['unfiltered'])) && (($search['unfiltered'] === 'true'))) {
			$search = array_merge($search, $indexFilter);
			$isUnfiltered = true;
		} # if
		
		# 
		# Vertaal nu een eventueel opgegeven boom naar daadwerkelijke subcategorieen
		# en dergelijke
		#
		if (!empty($search['tree'])) {
			# explode the dynaList
			$dynaList = explode(',', $search['tree']);
			list($categoryList, $strongNotList) = $this->prepareCategorySelection($dynaList);

			# en converteer de lijst met subcategorieen naar een lijst met SQL
			# filters
			$categorySql = $this->categoryListToSql($categoryList);
			$strongNotSql = $this->strongNotListToSql($strongNotList);
		} # if

		# Kijk nu of we nog een expliciete sorteermethode moeten meegeven 
		$sortFields = $this->prepareSortFields($sort, $sortFields);

		$endFilter = array();
		if (!empty($categorySql)) { 
			$endFilter[] = '(' . join(' OR ', $categorySql) . ') ';
		} # if
		if (!empty($filterValueSql['AND'])) {
			$endFilter[] = '(' . join(' AND ', $filterValueSql['AND']) . ') ';
		} # if
		if (!empty($filterValueSql['OR'])) {
			$endFilter[] = '(' . join(' OR ', $filterValueSql['OR']) . ') ';
		} # if
		$endFilter[] = join(' AND ', $strongNotSql);
		$endFilter = array_filter($endFilter);
		
		SpotTiming::stop(__FUNCTION__, array(join(" AND ", $endFilter)));
		return array('filter' => join(" AND ", $endFilter),
					 'categoryList' => $categoryList,
					 'unfiltered' => $isUnfiltered,
					 'strongNotList' => $strongNotList,
					 'filterValueList' => $filterValueList,
					 'additionalFields' => $additionalFields,
					 'additionalTables' => $additionalTables,
					 'additionalJoins' => $additionalJoins,
					 'sortFields' => $sortFields);
	} # filterToQuery
	
	public function setActiveRetriever($b) {
		$this->_activeRetriever = $b;
	} # setActiveRetriever

} # class SpotOverview
