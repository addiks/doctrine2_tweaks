<?php
/**
 * Copyright (C) 2013  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 * @package Addiks
 */

if (!function_exists("addiks_doctrinetweaks_auto_loader")) {
    $baseDir = dirname(dirname(__FILE__));

    function addiks_doctrinetweaks_auto_loader($className)
    {
        $baseDir = dirname(dirname(__FILE__));

        foreach ([
            "Addiks\\DoctrineTweaks\\" => "%s/src/%s.php",
        ] as $namespace => $format) {
            if (substr($className, 0, strlen($namespace)) === $namespace) {
                $fileRelevantClassName = substr($className, strlen($namespace));

                $fileName = str_replace("\\", "/", $fileRelevantClassName);

                $filePath = sprintf($format, $baseDir, $fileName);

                if (file_exists($filePath)) {
                    require_once($filePath);
                }
            }
        }
    }

    spl_autoload_register('addiks_doctrinetweaks_auto_loader');

    require_once("{$baseDir}/vendor/autoload.php");
}
