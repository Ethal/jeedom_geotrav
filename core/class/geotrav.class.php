<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class geotrav extends eqLogic {

    public static $_widgetPossibility = array('custom' => true);

    public function cron15() {
        foreach (eqLogic::byType('geotrav', true) as $location) {
            if ($location->getConfiguration('type') == 'station') {
                $location->refreshStation();
            }
            if ($location->getConfiguration('type') == 'travel') {
                $location->refreshTravel();
            }
        }
    }

    public function loadCmdFromConf($type) {
        if ($type == 'geofence') {
            return true;
            //nothing to do
        }
        if (!is_file(dirname(__FILE__) . '/../config/devices/' . $type . '.json')) {
            return;
        }
        $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $type . '.json');
        if (!is_json($content)) {
            return;
        }
        $device = json_decode($content, true);
        if (!is_array($device) || !isset($device['commands'])) {
            return true;
        }
        /*$this->import($device);*/
        foreach ($device['commands'] as $command) {
            $cmd = null;
            foreach ($this->getCmd() as $liste_cmd) {
                if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
                || (isset($command['name']) && $liste_cmd->getName() == $command['name'])) {
                    $cmd = $liste_cmd;
                    break;
                }
            }
            if ($cmd == null || !is_object($cmd)) {
                $cmd = new geotravCmd();
                $cmd->setEqLogic_id($this->getId());
                utils::a2o($cmd, $command);
                $cmd->save();
            }
        }
    }

    public function preSave() {
        if ($this->getConfiguration('type') == 'location') {
            $url = network::getNetworkAccess('external') . '/plugins/geotrav/core/api/jeeGeotrav.php?apikey=' . jeedom::getApiKey('geotrav') . '&id=' . $this->getId() . '&value=%LOCN';
            $this->setConfiguration('url',$url);
        }
    }

    public function postAjax() {
        $this->loadCmdFromConf($this->getConfiguration('type'));
        if ($this->getConfiguration('fieldcoordinate') != $this->getConfiguration('coordinate')) {
            $this->updateGeocodingReverse($this->getConfiguration('fieldcoordinate'));
        }
        if ($this->getConfiguration('fieldaddress') != $this->getConfiguration('address')) {
            $this->updateGeocoding($this->getConfiguration('fieldaddress'));
        }
        geotrav::updateGeofencingCmd();
        geotrav::triggerGlobal();
    }

    public function triggerGlobal() {
        $listener = listener::byClassAndFunction('geotrav', 'triggerGeo', array('geotrav' => 'global'));
        if (!is_object($listener)) {
            $listener = new listener();
        }
        $listener->setClass('geotrav');
        $listener->setFunction('triggerGeo');
        $listener->setOption(array('geotrav' => 'global'));
        $listener->emptyEvent();
        foreach (eqLogic::byType('geotrav', true) as $location) {
            if ($location->getConfiguration('type') == 'location') {
                $locationcmd = geotravCmd::byEqLogicIdAndLogicalId($location->getId(),'location:coordinate');
                $listener->addEvent($locationcmd->getId());
            }
        }
        $listener->save();
    }

    public static function triggerGeo($_option) {
        //$alarm = geotrav::byId($_option['geotrav']);//equal global
        log::add('geotrav', 'debug', 'Trigger ' . $_option['event_id'] . ' ' . $_option['value']);
        foreach (eqLogic::byType('geotrav', true) as $geotrav) {
            if ($geotrav->getConfiguration('type') == 'geofence') {
                $geotrav->updateGeofenceValues($_option['event_id'],$_option['value']);
            }
        }
    }

    public function updateGeofencingCmd() {
        foreach (eqLogic::byType('geotrav', true) as $geotrav) {
            if ($geotrav->getConfiguration('type') == 'geofence') {
                foreach (eqLogic::byType('geotrav', true) as $location) {
                    if ($location->getConfiguration('type') == 'location') {
                        $locationCmd= geotravCmd::byEqLogicIdAndLogicalId($location->getId(),'location:coordinate');
                        $geotravcmd = geotravCmd::byEqLogicIdAndLogicalId($geotrav->getId(),'geofence:'.$locationCmd->getId().'presence');
                        if (!is_object($geotravcmd)) {
                            $geotravcmd = new geotravCmd();
                            $geotravcmd->setName(__('Présence ' . $location->getName(), __FILE__));
                            $geotravcmd->setEqLogic_id($geotrav->id);
                            $geotravcmd->setLogicalId('geofence:'.$locationCmd->getId().'presence');
                            $geotravcmd->setType('info');
                            $geotravcmd->setSubType('binary');
                            $geotravcmd->setConfiguration('type','geofence');
                            $geotravcmd->save();
                        }
                        $geotravcmd = geotravCmd::byEqLogicIdAndLogicalId($geotrav->getId(),'geofence:'.$locationCmd->getId().'distance');
                        if (!is_object($geotravcmd)) {
                            $geotravcmd = new geotravCmd();
                            $geotravcmd->setName(__('Distance ' . $location->getName(), __FILE__));
                            $geotravcmd->setEqLogic_id($geotrav->id);
                            $geotravcmd->setLogicalId('geofence:'.$locationCmd->getId().'distance');
                            $geotravcmd->setType('info');
                            $geotravcmd->setSubType('numeric');
                            $geotravcmd->setUnite('m');
                            $geotravcmd->setConfiguration('type','geofence');
                            $geotravcmd->save();
                        }
                    }
                }
            }
        }
    }

    public function updateGeofenceValues($id,$coord) {
        log::add('geotrav', 'debug', 'In update ' . $this->getName() . ' ' . $this->getConfiguration('zoneOrigin'));
        $origin = geotrav::byId($this->getConfiguration('zoneOrigin'));
        log::add('geotrav', 'debug', 'In update ' . $origin->getConfiguration('coordinate') . ' ' . $coord);
        $coordinate1 = explode(',',$coord);
        $coordinate2 = explode(',',$origin->getConfiguration('coordinate'));
        $earth_radius = 6378137; // Terre = sphère de 6378km de rayon
        $rlo1 = deg2rad($coordinate1[1]);
        $rla1 = deg2rad($coordinate1[0]);
        $rlo2 = deg2rad($coordinate2[1]);
        $rla2 = deg2rad($coordinate2[0]);
        $dlo = ($rlo2 - $rlo1) / 2;
        $dla = ($rla2 - $rla1) / 2;
        $a = (sin($dla) * sin($dla)) + cos($rla1) * cos($rla2) * (sin($dlo) * sin($dlo));
        $d = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = round(($earth_radius * $d));
        log::add('geotrav', 'debug', 'Geofence ' . $distance);
        $this->checkAndUpdateCmd('geofence:'.$id.'distance', $distance);
        if ($distance < $this->getConfiguration('zoneConfiguration')) {
            $presence = true;
        } else {
            $presence = false;
        }
        $this->checkAndUpdateCmd('geofence:'.$id.'presence', $presence);
        $this->refreshWidget();
    }

    public function updateGeocodingReverse($geoloc) {
        $geoloc = str_replace(' ','',$geoloc);
        log::add('geotrav', 'debug', 'Coordonnées ' . $geoloc);
        if ($geoloc == '' || strrpos($geoloc,',') === false) {
            log::add('geotrav', 'error', 'Coordonnées invalides ' . $geoloc);
            return true;
        }
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' . $geoloc . '&key=' . config::byKey('keyGMG','geotrav');
        $data = file_get_contents($url);
        $jsondata = json_decode($data,true);
        $this->updateLocation($jsondata);
    }

    public function updateGeocoding($address) {
        log::add('geotrav', 'debug', 'Adresse ' . $address);
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . config::byKey('keyGMG','geotrav');
        $data = file_get_contents($url);
        $jsondata = json_decode($data,true);
        $this->updateLocation($jsondata);
    }

    public function updateLocation($jsondata) {
        $this->checkAndUpdateCmd('location:latitude', $jsondata['results'][0]['geometry']['location']['lat']);
        $this->checkAndUpdateCmd('location:longitude', $jsondata['results'][0]['geometry']['location']['lng']);
        $this->checkAndUpdateCmd('location:coordinate', $jsondata['results'][0]['geometry']['location']['lat'] . ',' . $jsondata['results'][0]['geometry']['location']['lng']);
        $this->checkAndUpdateCmd('location:address', isset($jsondata['results'][0]['formatted_address']) ? $jsondata['results'][0]['formatted_address']:'NA');
        $this->checkAndUpdateCmd('location:street', isset($jsondata['results'][0]['address_components'][0]['long_name']) ? $jsondata['results'][0]['address_components'][0]['long_name'] . ' ' . $jsondata['results'][0]['address_components'][1]['long_name']:'NA');
        $this->checkAndUpdateCmd('location:city', isset($jsondata['results'][0]['address_components'][2]['long_name']) ? $jsondata['results'][0]['address_components'][2]['long_name']:'NA');
        $this->checkAndUpdateCmd('location:zip', isset($jsondata['results'][0]['address_components'][6]['long_name']) ? $jsondata['results'][0]['address_components'][6]['long_name']:'NA');
        if (isset($jsondata['results'][0]['address_components'][6]['long_name']) && $jsondata['results'][0]['address_components'][5]['long_name'] == 'France') {
            $department = substr($jsondata['results'][0]['address_components'][6]['long_name'],0,2);
            if ($department == '20') {
                if ((int)$zip >= 20200) {
                    $department = '2B';
                } else {
                    $department = '2A';
                }
            }
        } else {
            $department = 'NA';
        }
        $this->checkAndUpdateCmd('location:department', $department);
        $this->checkAndUpdateCmd('location:country', isset($jsondata['results'][0]['address_components'][5]['long_name']) ? $jsondata['results'][0]['address_components'][5]['long_name']:'NA');
        $this->checkAndUpdateCmd('location:district', isset($jsondata['results'][0]['address_components'][3]['long_name']) ? $jsondata['results'][0]['address_components'][3]['long_name']:'NA');
        $this->setConfiguration('coordinate',$jsondata['results'][0]['geometry']['location']['lat'] . ',' . $jsondata['results'][0]['geometry']['location']['lng']);
        $this->setConfiguration('fieldcoordinate',$jsondata['results'][0]['geometry']['location']['lat'] . ',' . $jsondata['results'][0]['geometry']['location']['lng']);
        $this->setConfiguration('address',$jsondata['results'][0]['formatted_address']);
        $this->setConfiguration('fieldaddress',$jsondata['results'][0]['formatted_address']);
        $this->save();
        $this->refreshWidget();
    }

    public function refreshTravel($param='none') {
        $departureEq = geotrav::byId($this->getConfiguration('travelDeparture'));
        $arrivalEq = geotrav::byId($this->getConfiguration('travelArrival'));
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . urlencode($departureEq->getConfiguration('coordinate')) . '&destination=' . urlencode($arrivalEq->getConfiguration('coordinate')) . '&language=fr&key=' . config::byKey('keyGMG','geotrav');
        $url2 = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . urlencode($arrivalEq->getConfiguration('coordinate')) . '&destination=' . urlencode($departureEq->getConfiguration('coordinate')) . '&language=fr&key=' . config::byKey('keyGMG','geotrav');
        $options = array();
        if ($this->getConfiguration('travelOptions') != '') {
            $options = arg2array($this->getConfiguration('travelOptions'));
        }
        if ($param != 'none') {
            $options = arg2array($param);
        }
        foreach ($options as $key => $value) {
            if ($key == 'departure_time' || $key == 'arrival_time') {
                $value = substr_replace($value,':',-2,0);
            }
            $url .= '&' . $key . '=' . $value;
            $url2 .= '&' . $key . '=' . $value;
        }
        $data = file_get_contents($url);
        $jsondata = json_decode($data,true);
        $data = file_get_contents($url2);
        $jsondata2 = json_decode($data,true);
        log::add('geotrav', 'debug', 'Travel ' . $url);
        $this->checkAndUpdateCmd('travel:distance', round($jsondata['routes'][0]['legs'][0]['distance']['value']/1000,2));
        $this->checkAndUpdateCmd('travel:time', round($jsondata['routes'][0]['legs'][0]['duration']['value']/60));
        $etapes = '';
        foreach ($jsondata['routes'][0]['legs'][0]['steps'] as $elt) {
            $etapes .= $elt['html_instructions'] . '(' . $elt['distance']['text'] . ' ' . $elt['duration']['text'] . ')';
        }
        $this->checkAndUpdateCmd('travel:steps', $etapes);
        $this->checkAndUpdateCmd('travel:distanceback', round($jsondata2['routes'][0]['legs'][0]['distance']['value']/1000,2));
        $this->checkAndUpdateCmd('travel:timeback', round($jsondata2['routes'][0]['legs'][0]['duration']['value']/60));
        $etapes = '';
        foreach ($jsondata2['routes'][0]['legs'][0]['steps'] as $elt) {
            $etapes .= $elt['html_instructions'] . '(' . $elt['distance']['text'] . ' ' . $elt['duration']['text'] . ')';
        }
        $this->checkAndUpdateCmd('travel:stepsback', $etapes);
        $this->refreshWidget();
    }

    public function refreshStation($options='none') {
        $stationEq = geotrav::byId($this->getConfiguration('stationPoint'));
        $url = 'https://' . config::byKey('keyNavitia','geotrav') . '@api.navitia.io/v1/coord/' . urlencode($stationEq->getConfiguration('coordinate'));
        $data = file_get_contents($url);
        $jsondata = json_decode($data,true);
        log::add('geotrav', 'debug', 'Station ' . $url . print_r($jsondata,true));
        $this->refreshWidget();
    }

    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        if ($this->getDisplay('hideOn' . $version) == 1) {
            return '';
        }
        foreach ($this->getCmd('info') as $cmd) {
            $replace['#' . $cmd->getLogicalId() . '_history#'] = '';
            $replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
            $replace['#' . $cmd->getLogicalId() . '#'] = $cmd->execCmd();
            $replace['#' . $cmd->getLogicalId() . '_collect#'] = $cmd->getCollectDate();
            if ($cmd->getIsHistorized() == 1) {
                $replace['#' . $cmd->getLogicalId() . '_history#'] = 'history cursor';
            }
        }
        $templatename = $this->getConfiguration('type');

        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $templatename, 'geotrav')));
    }

}

class geotravCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        log::add('geotrav', 'debug', 'Action sur ' . $this->getLogicalId());
        switch ($this->getLogicalId()) {
            case 'location:updateCoo':
            $eqLogic->updateGeocodingReverse($_options['message']);
            break;
            case 'location:updateAdr':
            $eqLogic->updateGeocoding($_options['message']);
            break;
            case 'travel:refresh':
            $eqLogic->refreshTravel();
            break;
            case 'travel:refreshOptions':
            $eqLogic->refreshTravel($_options['message']);
            break;
            case 'station:refresh':
            $eqLogic->refreshStation();
            break;
            case 'station:refreshOptions':
            $eqLogic->refreshStation($_options['message']);
            break;
        }
    }

}

?>
