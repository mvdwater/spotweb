<?php

class Dao_Sqlite_Spot extends Dao_Base_Spot {

	/*
	 * Remove older spots from the database
	 */
	function deleteSpotsRetention($retention) {
		SpotTiming::start(__FUNCTION__);
		$retention = $retention * 24 * 60 * 60; // omzetten in seconden

		$this->_conn->modify("DELETE FROM spots WHERE spots.stamp < " . (time() - $retention) );
		$this->_conn->modify("DELETE FROM idx_fts_spots WHERE idx_fts_spots.rowid not in 
							(SELECT rowid FROM spots)") ;
		$this->_conn->modify("DELETE FROM spotsfull WHERE spotsfull.messageid not in 
							(SELECT messageid FROM spots)") ;
		$this->_conn->modify("DELETE FROM commentsfull WHERE messageid IN 
							(SELECT messageid FROM commentsxover WHERE commentsxover.nntpref not in 
							(SELECT messageid FROM spots))") ;
		$this->_conn->modify("DELETE FROM commentsxover WHERE commentsxover.nntpref not in 
							(SELECT messageid FROM spots)") ;
		$this->_conn->modify("DELETE FROM reportsxover WHERE reportsxover.nntpref not in 
							(SELECT messageid FROM spots)") ;
		$this->_conn->modify("DELETE FROM spotstatelist WHERE spotstatelist.messageid not in 
							(SELECT messageid FROM spots)") ;
		$this->_conn->modify("DELETE FROM reportsposted WHERE reportsposted.inreplyto not in 
							(SELECT messageid FROM spots)") ;
		$this->_conn->modify("DELETE FROM cache WHERE (cache.cachetype = %d OR cache.cachetype = %d) AND cache.resourceid not in 
							(SELECT messageid FROM spots)", Array(SpotCache::SpotImage, SpotCache::SpotNzb)) ;
		SpotTiming::stop(__FUNCTION__, array($retention));
	} # deleteSpotsRetention

	/*
	 * Returns the amount of spots currently in the database
	 * Special function for Sqlite because it needs the idx_fts_spots table
	 */
	function getSpotCount($sqlFilter) {
		SpotTiming::start(__FUNCTION__);
		if (empty($sqlFilter)) {
			$query = "SELECT COUNT(1) FROM spots AS s";
		} else {
			$query = "SELECT COUNT(1) FROM spots AS s
						LEFT JOIN idx_fts_spots AS idx_fts_spots ON idx_fts_spots.rowid = s.rowid
						LEFT JOIN spotsfull AS f ON s.messageid = f.messageid
						LEFT JOIN spotstatelist AS l ON s.messageid = l.messageid
						LEFT JOIN spotteridblacklist as bl ON ((bl.spotterid = s.spotterid) AND (bl.ouruserid = -1) AND (bl.idtype = 1))
						WHERE " . $sqlFilter . " AND (bl.spotterid IS NULL)";
		} # else
		$cnt = $this->_conn->singleQuery($query);
		SpotTiming::stop(__FUNCTION__, array($sqlFilter));
		if ($cnt == null) {
			return 0;
		} else {
			return $cnt;
		} # if
	} # getSpotCount

	/*
	 * Returns the amount of spots per hour
	 */
	function getSpotCountPerHour($limit) {
		$filter = ($limit) ? "WHERE stamp > " . strtotime("-1 " . $limit) : '';
		return $this->_conn->arrayQuery("SELECT strftime('%H', time(stamp, 'unixepoch')) AS data, count(*) AS amount FROM spots " . $filter . " GROUP BY data;");
	} # getSpotCountPerHour

	/*
	 * Returns the amount of spots per weekday
	 */
	function getSpotCountPerWeekday($limit) {
		$filter = ($limit) ? "WHERE stamp > " . strtotime("-1 " . $limit) : '';
		return $this->_conn->arrayQuery("SELECT strftime('%w', time(stamp, 'unixepoch')) AS data, count(*) AS amount FROM spots " . $filter . " GROUP BY data;");
	} # getSpotCountPerWeekday

	/*
	 * Returns the amount of spots per month
	 */
	function getSpotCountPerMonth($limit) {
		$filter = ($limit) ? "WHERE stamp > " . strtotime("-1 " . $limit) : '';
		return $this->_conn->arrayQuery("SELECT strftime('%m', time(stamp, 'unixepoch')) AS data, count(*) AS amount FROM spots " . $filter . " GROUP BY data;");
	} # getSpotCountPerMonth
	
} # Dao_Sqlite_Spot
