<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2017 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

/**
 * LMSEventManager
 *
 */
class LMSEventManager extends LMSManager implements LMSEventManagerInterface
{
	public function EventAdd($event) {
		$this->db->BeginTrans();

		$this->db->Execute('INSERT INTO events (title, description, date, begintime, enddate,
				endtime, userid, creationdate, private, customerid, type, address_id, nodeid, ticketid)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?NOW?, ?, ?, ?, ?, ?, ?)',
				array($event['title'],
					$event['description'],
					$event['date'],
					$event['begintime'],
					$event['enddate'],
					$event['endtime'],
					Auth::GetCurrentUser(),
					$event['private'],
					empty($event['custid']) ? null : $event['custid'],
					$event['type'],
					$event['address_id'],
					$event['nodeid'],
					empty($event['ticketid']) ? null : $event['ticketid'],
				));

		$id = $this->db->GetLastInsertID('events');

		if (!empty($event['userlist']))
			foreach ($event['userlist'] as $userid)
				$this->db->Execute('INSERT INTO eventassignments (eventid, userid)
					VALUES (?, ?)', array($id, $userid));

		$this->db->CommitTrans();

		return $id;
	}

	public function EventUpdate($event) {
		$this->db->BeginTrans();

		$this->db->Execute('UPDATE events SET title=?, description=?, date=?, begintime=?, enddate=?, endtime=?, private=?,
				note=?, customerid=?, type=?, address_id=?, nodeid=?, ticketid=? WHERE id=?',
			array($event['title'], $event['description'], $event['date'], $event['begintime'], $event['enddate'], $event['endtime'],
				$event['private'], $event['note'], empty($event['custid']) ? null : $event['custid'], $event['type'], $event['address_id'],
				$event['nodeid'], $event['helpdesk'], $event['id']));

		$this->db->Execute('DELETE FROM eventassignments WHERE eventid = ?', array($event['id']));
		if (!empty($event['userlist']) && is_array($event['userlist']))
			foreach ($event['userlist'] as $userid)
				$this->db->Execute('INSERT INTO eventassignments (eventid, userid) VALUES (?, ?)',
					array($event['id'], $userid));

		$this->db->Execute('UPDATE events SET moddate=?NOW?, moduserid=? WHERE id=?',
			array(Auth::GetCurrentUser(), $event['id']));

		$this->db->CommitTrans();
	}

	public function EventDelete($id) {
		if ($this->db->Execute('DELETE FROM events WHERE id = ?', array($id)))
			$this->db->Execute('DELETE FROM eventassignments WHERE eventid = ?', array($id));
	}

	public function GetEvent($id) {
		$event = $this->db->GetRow('SELECT e.id AS id, title, description, note, userid, e.creationdate,
			customerid, date, begintime, enddate, endtime, private, closed, e.type, '
			. $this->db->Concat('UPPER(c.lastname)',"' '",'c.name') . ' AS customername,
			vusers.name AS username, e.moddate, e.moduserid, e.closeddate, e.closeduserid,
			e.address_id, va.location, e.nodeid, n.name AS node_name, n.location AS node_location, '
			. $this->db->Concat('c.city',"', '",'c.address') . ' AS customerlocation,
			(SELECT name FROM vusers WHERE id=e.moduserid) AS modusername,
			(SELECT name FROM vusers WHERE id=e.closeduserid) AS closedusername, ticketid
			FROM events e
			LEFT JOIN vaddresses va ON va.id = e.address_id
			LEFT JOIN vnodes n ON (e.nodeid = n.id)
			LEFT JOIN customerview c ON (c.id = customerid)
			LEFT JOIN vusers ON (vusers.id = userid)
			WHERE e.id = ?', array($id));

		$event['wholedays'] = $event['endtime'] == 86400;
		$event['helpdesk'] = !empty($event['ticketid']);
		$event['userlist'] = $this->db->GetCol('SELECT userid AS id
			FROM vusers, eventassignments
			WHERE vusers.id = userid
			AND eventid = ?', array($id));
		if (empty($event['userlist']))
			$event['userlist'] = array();

		return $event;
	}

	/**
	 * @param array $params associative array of parameters described below:
	 * 		year - start date year (default: null = today's year): single integer value,
	 * 		month - start date month (default: null = today's month): single interget value,
	 * 		day - start date day (default: null = today's day): single integer value,
	 * 		forward - for how many days get events (default: 0 = undefined): single integer value,
	 * 			-1 = open overdued events till midnight,
	 * 		customerid - customer id assigned to events (default: 0 = any): single integer value,
	 *		userand - if all users should be assigned simultaneously (default: 0 = OR): single integer value:
	 * 			1 = AND,
	 * 		userid - user id assigned to events (default: 0 or null = any):
	 * 			array() of integer values or single integer value,
	 * 			-1 = events not assigned to any user,
	 * 		type - event type (default: 0 = any):
	 * 			array() of integer values or single integer value,
	 * 		privacy - event privacy flag (default: 0 = public events or private ones assigned to current user):
	 * 			single integer value,
	 * 			1 = public events,
	 * 			2 = private events assigned to current user,
	 * 		closed - event close flag (default: '' = any value): single integer value or empty string,
	 * 		count - count records only or return selected record interval
	 * 			true - count only,
	 * 			false - get records,
	 * 		offset - first returned record (null = 0),
	 * 		limit - returned record count (null = unlimited),
	 * @return mixed
	 */
	function GetEventList(array $params) {
		extract($params);
		foreach (array('year', 'month', 'day') as $var)
			if (!isset($$var))
				$$var = null;
		foreach (array('forward', 'customerid', 'userid', 'type', 'privacy') as $var)
			if (!isset($$var))
				$$var = 0;
		if (!isset($closed))
			$closed = '';
		if (!isset($count))
			$count = false;

        $t = time();

        if(!isset($year))
		    $year = date('Y', $t);
        if(!isset($month))
		    $month = date('n', $t);
        if(!isset($day))
		    $day = date('j', $t);

        switch ($privacy) {
            case 0:
                $privacy_condition = '(private = 0 OR (private = 1 AND userid = ' . intval(Auth::GetCurrentUser()) . '))';
                break;
            case 1:
                $privacy_condition = 'private = 0';
                break;
            case 2:
                $privacy_condition = 'private = 1 AND userid = ' . intval(Auth::GetCurrentUser());
                break;
        }

        if ($forward=='-1') {
            $closed = 0;
            $overduefilter = ' AND closed = 0 ';
            $startdate = 0;
            $enddate = strtotime("midnight", $t);
        } else {
        	$overduefilter = '';
            $startdate = mktime(0,0,0, $month, $day, $year);
            $enddate = mktime(0,0,0, $month, $day+$forward, $year);
        }

        if ($closed != '')
            $closedfilter = ' AND closed = '.intval($closed);
        else
        	$closedfilter = '';

		if (!isset($userid) || empty($userid))
			$userfilter = '';
		else {
			if (is_array($userid)) {
				if (in_array('-1', $userid))
					$userfilter = ' AND NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)';
				else {
					if ($userand)
						$userfilter = ' AND EXISTS (SELECT COUNT(userid), eventid FROM eventassignments WHERE eventid = events.id AND userid IN ('
							. implode(',', $userid) . ') GROUP BY eventid HAVING(COUNT(eventid) = ' . count($userid) . '))';
					else
						$userfilter = ' AND EXISTS ( SELECT 1 FROM eventassignments WHERE eventid = events.id AND userid IN (' . implode(',', $userid) . '))';
				}
			} else {
				$userid = intval($userid);
				if ($userid == -1)
					$userfilter = ' AND NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)';
				else
					$userfilter = ' AND EXISTS ( SELECT 1 FROM eventassignments WHERE eventid = events.id AND userid = ' . $userid . ')';

			}
		}

		if ($count)
			return $this->db->GetOne(
				'SELECT COUNT(events.id)
				FROM events
				LEFT JOIN vaddresses va ON va.id = events.address_id
				LEFT JOIN vnodes as vn ON (nodeid = vn.id)
				LEFT JOIN customerview c ON (events.customerid = c.id)
				LEFT JOIN vusers ON (userid = vusers.id)
				WHERE ((date >= ? AND date < ?) OR (enddate != 0 AND date < ? AND enddate >= ?)) AND '
				. $privacy_condition
				. ($customerid ? ' AND events.customerid = '.intval($customerid) : '')
				. $userfilter
				. $overduefilter
				. (!empty($type) ? ' AND events.type ' . (is_array($type) ? 'IN (' . implode(',', Utils::filterIntegers($type)) . ')' : '=' . intval($type)) : '')
				. $closedfilter,
				array($startdate, $enddate, $enddate, $startdate));

		$list = $this->db->GetAll(
			'SELECT events.id AS id, title, note, description, date, begintime, enddate, endtime, events.customerid as customerid, closed, events.type, '
				. $this->db->Concat('UPPER(c.lastname)',"' '",'c.name').' AS customername, nn.id AS netnode_id, nn.name AS netnode_name, vd.address AS netnode_location,
				userid, vusers.name AS username, ' . $this->db->Concat('c.city',"', '",'c.address').' AS customerlocation,
				events.address_id, va.location, events.nodeid as nodeid, vn.location AS nodelocation, ticketid
			FROM events
			LEFT JOIN vaddresses va ON va.id = events.address_id
			LEFT JOIN vnodes as vn ON (nodeid = vn.id)
			LEFT JOIN customerview c ON (events.customerid = c.id)
			LEFT JOIN vusers ON (userid = vusers.id)
			LEFT JOIN rttickets as rtt ON (rtt.id = events.ticketid)
			LEFT JOIN netnodes as nn ON (nn.id = rtt.netnodeid)
			LEFT JOIN vaddresses as vd ON (vd.id = nn.address_id)
			WHERE ((date >= ? AND date < ?) OR (enddate != 0 AND date < ? AND enddate >= ?)) AND '
			. $privacy_condition
			.($customerid ? ' AND events.customerid = '.intval($customerid) : '')
			. $userfilter
			. $overduefilter
			. (!empty($type) ? ' AND events.type ' . (is_array($type) ? 'IN (' . implode(',', Utils::filterIntegers($type)) . ')' : '=' . intval($type)) : '')
			. $closedfilter
			.' ORDER BY date, begintime'
			. (isset($limit) ? ' LIMIT ' . $limit : '')
			. (isset($offset) ? ' OFFSET ' . $offset : ''),
			array($startdate, $enddate, $enddate, $startdate));
        $list2 = array();
        if ($list)
            foreach ($list as $idx => $row) {
                $row['userlist'] = $this->db->GetAll('SELECT userid AS id, vusers.name
					FROM eventassignments, vusers
					WHERE userid = vusers.id AND eventid = ? ',
                    array($row['id']));
                $endtime = $row['endtime'];
                if ($row['enddate'] && ($row['enddate'] - $row['date'])) {
                    $days = round(($row['enddate'] - $row['date']) / 86400);
                    $row['enddate'] = $row['date'] + 86400;
                    //$row['endtime'] = 0;
                    $dst = date('I', $row['date']);
                    $list2[] = $row;
                    while ($days) {
                        //if ($days == 1)
                            $row['endtime'] = $endtime;
                        $row['date'] += 86400;
                        $newdst = date('I', $row['date']);
                        if ($newdst != $dst) {
                            if ($newdst < $dst)
                                $row['date'] += 3600;
                            else
                                $row['date'] -= 3600;
                            $newdst = date('I', $row['date']);
                        }
                        list ($year, $month, $day) = explode('/', date('Y/n/j', $row['date']));
                        $row['date'] = mktime(0, 0, 0, $month, $day, $year);
                        $row['enddate'] = $row['date'] + 86400;
                        if ($days > 1 || $endtime)
                            $list2[] = $row;
                        $days--;
                        $dst = $newdst;
                    }
                } else
                    $list2[] = $row;
            }
        unset($t);
        return $list2;
    }

    public function EventSearch($search, $order = 'date,asc', $simple = false)
    {
        list($order, $direction) = sscanf($order, '%[^,],%s');

        (strtolower($direction) != 'desc') ? $direction = 'ASC' : $direction = 'DESC';

        switch ($order) {
            default:
                $sqlord = ' ORDER BY date ' . $direction . ', begintime ' . $direction;
                break;
        }

        $datefrom = intval($search['datefrom']);
        $dateto = intval($search['dateto']);
        $ticketid = intval($search['ticketid']);

        $list = $this->db->GetAll(
                'SELECT events.id AS id, title, description, date, begintime, enddate, endtime, customerid, closed, events.type, events.ticketid,'
                . $this->db->Concat('customers.lastname', "' '", 'customers.name') . ' AS customername
			FROM events
			LEFT JOIN customers ON (customerid = customers.id)
			WHERE (private = 0 OR (private = 1 AND userid = ?)) '
                . ($datefrom ? " AND (date >= $datefrom OR (enddate <> 0 AND enddate >= $datefrom))" : '')
                . ($dateto ? " AND (date <= $dateto OR (enddate <> 0 AND enddate <= $dateto))" : '')
                . (!empty($search['customerid']) ? ' AND customerid = ' . intval($search['customerid']) : '')
                . (!empty($search['type']) ? ' AND events.type = ' . intval($search['type']) : '')
                . ($ticketid ? " AND ticketid = " . $ticketid : '')
                . (isset($search['closed']) ? ($search['closed'] == '' ? '' : ' AND closed = ' . intval($search['closed'])) : ' AND closed = 0')
                . (!empty($search['title']) ? ' AND title ?LIKE? ' . $this->db->Escape('%' . $search['title'] . '%') : '')
                . (!empty($search['description']) ? ' AND description ?LIKE? ' . $this->db->Escape('%' . $search['description'] . '%') : '')
                . (!empty($search['note']) ? ' AND note ?LIKE? ' . $this->db->Escape('%' . $search['note'] . '%') : '')
                . $sqlord, array(Auth::GetCurrentUser()));

        if ($search['userid'])
            if (is_array($search['userid']))
                $users = array_filter($search['userid'], 'is_natural');
            else
                $users = array(intval($search['userid']));
        else
            $users = array();

        $list2 = $list3 = array();
        if ($list) {
            foreach ($list as $idx => $row) {
                if (!$simple)
                    $row['userlist'] = $this->db->GetAll('SELECT userid AS id, vusers.name
						FROM eventassignments, vusers
						WHERE userid = vusers.id AND eventid = ? ', array($row['id']));
                $endtime = $row['endtime'];

                $userfilter = false;
                if (!empty($users) && !empty($row['userlist']))
                    foreach ($row['userlist'] as $user)
                        if (in_array($user['id'], $users))
                            $userfilter = true;

                if ($row['enddate']) {
                    $days = intval(($row['enddate'] - $row['date']) / 86400);
                    //$row['endtime'] = 0;
                    if ((!$datefrom || $row['date'] >= $datefrom) &&
                            (!$dateto || $row['date'] <= $dateto)) {
                        $list2[] = $row;
                        if ($userfilter)
                            $list3[] = $row;
                    }

                    while ($days) {
                        //if ($days == 1)
                            $row['endtime'] = $endtime;
                        $row['date'] += 86400;

                        if ((!$datefrom || $row['date'] >= $datefrom) &&
                                (!$dateto || $row['date'] <= $dateto)) {
                            $list2[] = $row;
                            if ($userfilter)
                                $list3[] = $row;
                        }

                        $days--;
                    }
                } else
                if ((!$datefrom || $row['date'] >= $datefrom) &&
                        (!$dateto || $row['date'] <= $dateto)) {
                    $list2[] = $row;
                    if ($userfilter)
                        $list3[] = $row;
                }
            }

            if ($search['userid'])
                return $list3;
            else
                return $list2;
        }
    }

    public function GetCustomerIdByTicketId($id)
    {
        return $this->db->GetOne('SELECT customerid FROM rttickets WHERE id=?', array($id));
    }

	/**
	 * @param array $params associative array of parameters described below:
	 * 		users - event user assignments: array() of integer values,
	 * 			empty array() means empty overlapping user set,
	 * 		begindate - event start date in unix timestamp format,
	 *		enddate - event end date in unix timestamp format,
	 * 		begintime - event start time in HHMM format,
	 * 		endtime - event end time in HHMM format,
	 * @return mixed - users assigned to events taking $params into account;
	 * 		users parameter means user set to test
	 */
	public function EventOverlaps(array $params) {
		$users = array();

		if (empty($params['users']))
			return $users;

		extract($params);
		if (empty($enddate))
			$enddate = $begindate;
		$users = Utils::filterIntegers($users);

		return $this->db->GetCol('SELECT DISTINCT a.userid FROM events e
                        JOIN eventassignments a ON a.eventid = e.id
                        WHERE a.userid IN (' . implode(',', $users) . ')
                                AND (date < ? OR (date = ? AND begintime < ?))
                                AND (enddate > ? OR (enddate = ? AND endtime > ?))',
			array($enddate, $enddate, $endtime, $begindate, $begindate, $begintime));
	}

	public function AssignUserToEvent($id, $userid) {
		if (!$this->db->GetOne('SELECT eventid FROM eventassignments WHERE eventid = ? AND userid = ?', array($id, $userid)))
			$this->db->Execute('INSERT INTO eventassignments (eventid, userid) VALUES (?, ?)', array($id, $userid));
	}

	public function UnassignUserFromEvent($id, $userid) {
		$this->db->Execute('DELETE FROM eventassignments WHERE eventid = ? AND userid = ?', array($id, $userid));
	}

	public function MoveEvent($id, $delta) {
		$res = $this->db->Execute('UPDATE events SET date = date + ? WHERE id = ?', array($delta, $id));
		return $res && $this->db->Execute('UPDATE events SET enddate = enddate + ? WHERE id = ? AND enddate > 0', array($delta, $id));
	}
}
