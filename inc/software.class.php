<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 ocsinventoryng plugin for GLPI
 Copyright (C) 2015-2016 by the ocsinventoryng Development Team.

 https://github.com/pluginsGLPI/ocsinventoryng
 -------------------------------------------------------------------------

 LICENSE

 This file is part of ocsinventoryng.

 ocsinventoryng is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 ocsinventoryng is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with ocsinventoryng. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Class PluginOcsinventoryngSoftware
 */
class PluginOcsinventoryngSoftware extends CommonDBChild {

   // From CommonDBChild
   static public $itemtype = 'Computer';
   static public $items_id = 'computers_id';

   static $rightname = "plugin_ocsinventoryng";


   /**
    * Update config of a new software
    *
    * This function create a new software in GLPI with some general data.
    *
    * @param array   $cfg_ocs OCSNG mode configuration
    * @param integer $computers_id computer's id in GLPI
    * @param array   $ocsComputer
    * @param integer $entity the entity in which the peripheral will be created
    * @param array   $officepack
    * @param array   $ocsOfficePack
    *
    * @return Nothing .
    */
   static function updateSoftware($cfg_ocs, $computers_id, $ocsComputer, $entity, $officepack, $ocsOfficePack, $force = 0) {
      global $DB;

      if ($force) {
         self::resetSoftwares($computers_id, $cfg_ocs['history_software']);
      }
      if ($officepack) {
         PluginOcsinventoryngOfficepack::resetOfficePack($computers_id, $cfg_ocs['history_plugins']);
      }

      $is_utf8                  = $cfg_ocs["ocs_db_utf8"];
      $computer_softwareversion = new Computer_SoftwareVersion();
      //---- Get all the softwares for this machine from OCS -----//

      $soft = new Software();

      // Read imported software in last sync
      $query    = "SELECT `glpi_computers_softwareversions`.`id` as id,
                             `glpi_softwares`.`name` as sname,
                             `glpi_softwareversions`.`name` as vname
                      FROM `glpi_computers_softwareversions`
                      INNER JOIN `glpi_softwareversions`
                              ON `glpi_softwareversions`.`id`= `glpi_computers_softwareversions`.`softwareversions_id`
                      INNER JOIN `glpi_softwares`
                              ON `glpi_softwares`.`id`= `glpi_softwareversions`.`softwares_id`
                      WHERE `glpi_computers_softwareversions`.`computers_id`= $computers_id
                            AND `is_dynamic` = 1";
      $imported = [];

      foreach ($DB->request($query) as $data) {
         $imported[$data['id']] = strtolower($data['sname'] . PluginOcsinventoryngOcsProcess::FIELD_SEPARATOR . $data['vname']);
      }

      if ($officepack) {
         // Read imported software in last sync
         $query             = "SELECT `glpi_computers_softwarelicenses`.`id` as id,
                          `glpi_softwares`.`name` as sname,
                          `glpi_softwarelicenses`.`name` as lname,
                          `glpi_softwareversions`.`id` as vid
                   FROM `glpi_computers_softwarelicenses`
                   INNER JOIN `glpi_softwarelicenses`
                           ON `glpi_softwarelicenses`.`id`= `glpi_computers_softwarelicenses`.`softwarelicenses_id`
                   INNER JOIN `glpi_softwares`
                           ON `glpi_softwares`.`id`= `glpi_softwarelicenses`.`softwares_id`
                   INNER JOIN `glpi_softwareversions`
                           ON `glpi_softwarelicenses`.`softwareversions_id_use` = `glpi_softwareversions`.`id`
                   WHERE `glpi_computers_softwarelicenses`.`computers_id`= $computers_id
                         AND `is_dynamic` = 1";
         $imported_licences = [];

         foreach ($DB->request($query) as $data) {
            $imported_licences[$data['id']] = strtolower($data['vid']);
         }
      }

      foreach ($ocsComputer as $software) {
         $software = Toolbox::clean_cross_side_scripting_deep(Toolbox::addslashes_deep($software));

         //As we cannot be sure that data coming from OCS are in utf8, let's try to encode them
         //if possible
         foreach (['NAME', 'PUBLISHER', 'VERSION'] as $field) {
            if (isset($software[$field])) {
               $software[$field] = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $software[$field]);
            }
         }
         $manufacturer = "";
         //Replay dictionnary on manufacturer
         if (isset($software["PUBLISHER"])) {
            $manufacturer = Manufacturer::processName($software["PUBLISHER"]);
         }
         $version     = isset($software['VERSION']) ? $software['VERSION'] : "";
         $name        = isset($software['NAME']) ? $software['NAME'] : "";
         $installdate = isset($software['INSTALLDATE']) ? $software['INSTALLDATE'] : "";

         //Software might be created in another entity, depending on the entity's configuration
         $target_entity = Entity::getUsedConfig('entities_id_software', $entity);
         //Do not change software's entity except if the dictionnary explicity changes it
         if ($target_entity < 0) {
            $target_entity = $entity;
         }
         $modified_name       = $name;
         $modified_version    = $version;
         $version_comments    = isset($software['COMMENTS']) ? $software['COMMENTS'] : "";
         $is_helpdesk_visible = null;
         if (!$cfg_ocs["use_soft_dict"]) {
            //Software dictionnary
            $params         = ["name"         => $name,
                               "manufacturer" => $manufacturer,
                               "old_version"  => $version,
                               "entities_id"  => $entity];
            $rulecollection = new RuleDictionnarySoftwareCollection();
            $res_rule       = $rulecollection->processAllRules(Toolbox::stripslashes_deep($params),
                                                               [],
                                                               Toolbox::stripslashes_deep(['version' => $version]));

            if (isset($res_rule["name"])
                && $res_rule["name"]) {
               $modified_name = $res_rule["name"];
            }

            if (isset($res_rule["version"])
                && $res_rule["version"]) {
               $modified_version = $res_rule["version"];
            }

            if (isset($res_rule["is_helpdesk_visible"])
                && strlen($res_rule["is_helpdesk_visible"])) {

               $is_helpdesk_visible = $res_rule["is_helpdesk_visible"];
            }

            if (isset($res_rule['manufacturer'])
                && $res_rule['manufacturer']) {
               $manufacturer = Toolbox::addslashes_deep($res_rule["manufacturer"]);
            }

            //If software dictionnary returns an entity, it overrides the one that may have
            //been defined in the entity's configuration
            if (isset($res_rule["new_entities_id"])
                && strlen($res_rule["new_entities_id"])) {
               $target_entity = $res_rule["new_entities_id"];
            }
         }

         //If software must be imported
         if (!isset($res_rule["_ignore_import"])
             || !$res_rule["_ignore_import"]) {
            // Clean software object
            $soft->reset();

            // EXPLANATION About dictionnaries
            // OCS dictionnary : if software name change, as we don't store INITNAME
            //     GLPI will detect an uninstall (oldname) + install (newname)
            // GLPI dictionnary : is rule have change
            //     if rule have been replayed, modifiedname will be found => ok
            //     if not, GLPI will detect an uninstall (oldname) + install (newname)

            $id = array_search(strtolower(stripslashes($modified_name . PluginOcsinventoryngOcsProcess::FIELD_SEPARATOR . $modified_version)),
                               $imported);

            $isNewSoft = $soft->addOrRestoreFromTrash($modified_name, $manufacturer,
                                                      $target_entity,
                                                      '',
               ($entity != $target_entity),
                                                      $is_helpdesk_visible);

            if ($id) {
               //-------------------------------------------------------------------------//
               //---- The software exists in this version for this computer - Update comments --------------//
               //----  Update date install --------------//
               //---------------------------------------------------- --------------------//

               //Update version for this software
               if ($versionID = self::updateVersion($isNewSoft, $modified_version,
                                                    $version_comments,
                                                    $cfg_ocs['history_software']) == !false) {
                  //Update version for this machine
                  self::updateSoftwareVersion($computers_id, $versionID, $installdate,
                                              $cfg_ocs['history_software']);
               }
               unset($imported[$id]);
            } else {
               //------------------------------------------------------------------------//
               //---- The software doesn't exists in this version for this computer -----//
               //------------------------------------------------------------------------//

               //Import version for this software
               $versionID = self::importVersion($cfg_ocs, $isNewSoft, $modified_version, $version_comments);
               //Install version for this machine
               self::installSoftwareVersion($computers_id, $versionID, $installdate,
                                            $cfg_ocs['history_software']);
            }
            if ($officepack && count($ocsOfficePack) > 0) {
               // Get import officepack
               PluginOcsinventoryngOfficepack::updateOfficePack($computers_id, $isNewSoft, $name, $versionID, $entity,
                                                                $ocsOfficePack,
                                                                $cfg_ocs,
                                                                $imported_licences);
            }
         }
      }

      foreach ($imported as $id => $unused) {
         $computer_softwareversion->delete(['id' => $id, '_no_history' => !$cfg_ocs['history_software']],
                                           true,
                                           $cfg_ocs['history_software']);
         // delete cause a getFromDB, so fields contains values
         $verid = $computer_softwareversion->getField('softwareversions_id');
         $dbu   = new DbUtils();
         if ($dbu->countElementsInTable('glpi_computers_softwareversions', ["softwareversions_id" => $verid]) == 0
             && $dbu->countElementsInTable('glpi_softwarelicenses', ["softwareversions_id_buy" => $verid]) == 0) {

            $vers = new SoftwareVersion();
            if ($vers->getFromDB($verid)
                && $dbu->countElementsInTable('glpi_softwarelicenses',
                                              ["softwares_id" => $vers->fields['softwares_id']]) == 0
                && $dbu->countElementsInTable('glpi_softwareversions',
                                              ["softwares_id" => $vers->fields['softwares_id']]) == 1) {
               // 1 is the current to be removed
               $soft->putInTrash($vers->fields['softwares_id'],
                                 __('Software deleted by OCSNG synchronization', 'ocsinventoryng'));
            }
            $vers->delete(["id" => $verid, '_no_history' => !$cfg_ocs['history_software']],
                          true,
                          $cfg_ocs['history_software']);
         }
      }

      if ($officepack) {
         $dbu                       = new DbUtils();
         $computer_softwarelicenses = new Computer_SoftwareLicense();
         foreach ($imported_licences as $id => $unused) {
            $computer_softwarelicenses->delete(['id' => $id], true, $cfg_ocs['history_software']);
            // delete cause a getFromDB, so fields contains values
            $verid = $computer_softwarelicenses->getField('softwareversions_id');

            if ($dbu->countElementsInTable('glpi_computers_softwarelicenses', ["softwarelicenses_id" => $verid]) == 0) {

               $vers = new SoftwareVersion();
               if ($vers->getFromDB($verid)
                   && $dbu->countElementsInTable('glpi_softwarelicenses',
                                                 ["softwares_id" => $vers->fields['softwares_id']]) == 0) {
                  $soft = new Software();
                  $soft->delete(['id' => $vers->fields['softwares_id']], 1);
               }
               $vers->delete(["id" => $verid]);
            }
         }
      }
   }


   /**
    * Update a software on a computer - check if not already installed
    *
    * @param     $computers_id ID of the computer where to install a software
    * @param     $softwareversions_id ID of the version to install
    * @param     $installdate
    * @param int $dohistory
    *
    */
   static function updateSoftwareVersion($computers_id, $softwareversions_id, $installdate, $dohistory = 1) {
      global $DB;

      if (!empty($softwareversions_id) && $softwareversions_id > 0) {
         $query_exists = "SELECT `id`
                          FROM `glpi_computers_softwareversions`
                          WHERE (`computers_id` = $computers_id
                                 AND `softwareversions_id` = $softwareversions_id)";
         $result       = $DB->query($query_exists);

         if ($DB->numrows($result) > 0) {
            $data = $DB->fetch_array($result);
            $tmp  = new Computer_SoftwareVersion();

            $input = ['id'           => $data['id'],
                      '_no_history'  => !$dohistory,
                      'date_install' => $installdate];
            $tmp->update($input);
         }
      }
   }


   /**
    * Update config of a new version
    *
    * This function create a new software in GLPI with some general datas.
    *
    * @param $software : id of a software.
    * @param $version : version of the software
    *
    * @param $comments
    * @param $dohistory
    * return int/bool : inserted version id or false.
    */
   static function updateVersion($software, $version, $comments, $dohistory) {
      global $DB;

      $query  = "SELECT `id`
                FROM `glpi_softwareversions`
                WHERE `softwares_id` = $software
                AND `name` = '$version'";
      $result = $DB->query($query);

      if ($DB->numrows($result) > 0) {
         $data             = $DB->fetch_array($result);
         $input["id"]      = $data["id"];
         $input["comment"] = $comments;
         $vers             = new SoftwareVersion();
         $vers->update($input, $dohistory);
         return $data["id"];
      }

      return false;
   }

   /**
    * Import config of a new version
    *
    * This function create a new software in GLPI with some general datas.
    *
    * @param $cfg_ocs
    * @param $software : id of a software.
    * @param $version : version of the software
    *
    * @param $comments
    *
    * @return int : inserted version id.
    */
   static function importVersion($cfg_ocs, $software, $version, $comments) {
      global $DB;

      $isNewVers = 0;
      $query     = "SELECT `id`
                FROM `glpi_softwareversions`
                WHERE `softwares_id` = $software
                      AND `name` = '$version'";
      $result    = $DB->query($query);

      if ($DB->numrows($result) > 0) {
         $data      = $DB->fetch_array($result);
         $isNewVers = $data["id"];
      }

      if (!$isNewVers) {
         $vers = new SoftwareVersion();
         // TODO : define a default state ? Need a new option in config
         // Use $cfg_ocs["states_id_default"] or create a specific one?
         $input["softwares_id"] = $software;
         $input["name"]         = $version;
         $input["comment"]      = $comments;
         $isNewVers             = $vers->add($input, [], $cfg_ocs['history_software']);
      }

      return ($isNewVers);
   }


   /**
    * Install a software on a computer - check if not already installed
    *
    * @param        $computers_id ID of the computer where to install a software
    * @param        $softwareversions_id ID of the version to install
    * @param        $installdate
    * @param Do|int $dohistory Do history?
    *
    */
   static function installSoftwareVersion($computers_id, $softwareversions_id, $installdate, $dohistory = 1) {
      global $DB;

      if (!empty($softwareversions_id) && $softwareversions_id > 0) {
         $query_exists = "SELECT `id`
                          FROM `glpi_computers_softwareversions`
                          WHERE (`computers_id` = $computers_id
                           AND `softwareversions_id` = $softwareversions_id)";
         $result       = $DB->query($query_exists);

         if ($DB->numrows($result) > 0) {
            return $DB->result($result, 0, "id");
         }

         $tmp = new Computer_SoftwareVersion();
         $tmp->add(['computers_id'        => $computers_id,
                    'softwareversions_id' => $softwareversions_id,
                    'date_install'        => $installdate,
                    'is_dynamic'          => 1,
                    'is_deleted'          => 0], [], $dohistory);
      }
   }

   /**
    * Delete all old softwares of a computer.
    *
    * @param $glpi_computers_id integer : glpi computer id.
    *
    * @param $cfg_ocs
    *
    * @return nothing .
    */
   static function resetSoftwares($glpi_computers_id, $history_software) {
      global $DB;

      $query  = "SELECT *
                FROM `glpi_computers_softwareversions`
                WHERE `computers_id` = $glpi_computers_id
                     AND `is_dynamic` = 1";
      $result = $DB->query($query);

      if ($DB->numrows($result) > 0) {
         while ($data = $DB->fetch_assoc($result)) {
            $query2  = "SELECT COUNT(*)
                       FROM `glpi_computers_softwareversions`
                       WHERE `softwareversions_id` = " . $data['softwareversions_id'];
            $result2 = $DB->query($query2);

            if ($DB->result($result2, 0, 0) == 1) {
               $vers = new SoftwareVersion();
               $vers->getFromDB($data['softwareversions_id']);
               $query3  = "SELECT COUNT(*)
                          FROM `glpi_softwareversions`
                          WHERE `softwares_id`= " . $vers->fields['softwares_id'];
               $result3 = $DB->query($query3);

               if ($DB->result($result3, 0, 0) == 1) {
                  $soft = new Software();
                  $soft->delete(['id'          => $vers->fields['softwares_id'],
                                 '_no_history' => !$history_software],
                                true,
                                $history_software);
               }
               $vers->delete(["id"          => $data['softwareversions_id'],
                              '_no_history' => !$history_software],
                             true,
                             $history_software);
            }
         }

         $csv = new Computer_SoftwareVersion();
         $csv->deleteByCriteria(['computers_id' => $glpi_computers_id,
                                 'is_dynamic'   => 1], 1, $history_software);

      }
   }
}
