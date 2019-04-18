<?php

namespace Wilr\SilverStripe\Tasker\Traits;

use SilverStripe\ORM\DB;
use SilverStripe\Core\Convert;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Masterminds\HTML5;
use SilverStripe\Assets\File;
use Exception;

trait TaskHelpers
{
    /**
     * Copies the data from an old subclass table into the new class table.
     * Assumes that the underlying record (such as a page) still exists.
     *
     * @param string $oldTable
     * @param string $newClass
     * @param array $mapping
     * @param callable $callback
     */
    public function migrateTableToExistingTable($tableName, $newClass, $mapping = [], $callback = null, $insert = true)
    {
        if ($this->hasTable($tableName)) {
            $data = DB::query("SELECT * FROM $tableName ORDER BY ID");

            while ($record = $data->record()) {
                $baseRecord = $newClass::get()->byId($record['ID']);

                if (!$baseRecord) {
                    // check the base table for the new class
                    if ($base = DataObject::getSchema()->baseDataClass($newClass)) {
                        if ($base != $newClass) {
                            $baseRecord = $base::get()->byId($record['ID']);
                        }
                    }

                    if (!$baseRecord) {
                        if ($insert) {
                            $baseRecord = $newClass::create();
                            $baseRecord->setField('ID', $record['ID']);
                        } else {
                            continue;
                        }
                    } else {
                        $baseRecord = $baseRecord->newClassInstance($newClass);
                    }
                }

                if ($baseRecord->hasMethod('publishRecursive') && $baseRecord->isPublished()) {
                    $publish = true;
                } else {
                    $publish = false;
                }

                foreach ($record as $k => $v) {
                    $d = null;

                    if (isset($mapping[$k])) {
                        if (is_array($mapping[$k])) {
                            foreach ($mapping[$k] as $dd) {
                                $instance->{$dd} = $v;
                            }
                        } else {
                            $d = $mapping[$k];
                        }
                    } else {
                        $d = $k;
                    }

                    if ($d) {
                        $baseRecord->{$d} = $v;
                    }
                }

                if ($callback) {
                    $callback($instance);
                }

                try {
                    $baseRecord->write();

                    if ($publish) {
                        $baseRecord->publishRecursive();
                    }

                    if ($this->verbose) {
                        $this->echoMessage('Upgraded '. $tableName . '#'. $record['ID'] . ' over to '. $newClass . '#'. $baseRecord->ID);
                    } else {
                        $this->echoProgress();
                    }
                } catch (Exception $e) {
                    $this->echoWarning(sprintf(
                        'Could not write %s#%s during migration: Exception %s',
                        $tableName,
                        $baseRecord->ID,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->echoProgress();
    }

    public function escapeNamespace($class)
    {
        $class = str_replace('/', '\\', $class);
        return Convert::raw2sql($class);
    }

    /**
     * Returns whether a table exists
     *
     * @param string $table
     *
     * @return boolean
     */
    public function hasTable($tableName)
    {
        return DB::get_conn()->getSchemaManager()->hasTable($tableName);
    }


    /**
     * @param string $message
     */
    protected function query($sql)
    {
        if ($this->verbose) {
            echo $sql . PHP_EOL . PHP_EOL;
        }

        return DB::query($sql);
    }

    protected function tableHasCol($table, $col)
    {
        if (!$this->hasTable($table)) {
            return false;
        }

        $result = $this->query(sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'",
            $table,
            $col
        ));

        if ($result->value()) {
            return true;
        }

        return false;
    }

    /**
     * Helper function to archive a page from the live and draft stages. The
     * CMS was full of dodgy old pages which cluttered things up. The test site
     * should be used for experimenting, not production!
     *
     * @param int $pageID
     */
    public function archivePage($pageID)
    {
        $page = Versioned::get_by_stage(SiteTree::class, 'Stage', "ID = ". $pageID)->first();

        if (!$page || !$page->exists()) {
            $page = Versioned::get_by_stage(SiteTree::class, 'Live', "ID = ". $pageID)->first();
        }

        // bypass orm?
        $forceDelete = false;

        if (!$page || !$page->exists()) {
            $forceDelete = true;
        } elseif ($page) {
            try {
                // page ondelete automatically deletes the stageChildren
                if (!$page->isOnDraftOnly()) {
                    if ($this->verbose) {
                        $this->echoMessage('Deleting #'. $page->ID . ' '. $page->Title . ' from live');
                    }

                    $page->deleteFromStage('Live');
                }

                if (!$page->isOnLiveOnly()) {
                    if ($this->verbose) {
                        $this->echoMessage('Deleting #'. $page->ID . ' '. $page->Title . ' from draft');
                    }

                    $page->deleteFromStage('Stage');
                }

                if ($this->verbose) {
                    $this->echoMessage('Deleting #'. $page->ID . ' '. $page->Title);
                }

                $page->delete();
            } catch (Exception $e) {
                $this->echoWarning('Exception archiving page, opting for force.'. $e->getMessage());

                $forceDelete = true;
            }

            $page->flushCache();
        } else {
            if ($this->verbose) {
                $this->echoWarning('Could not find SiteTree#'. $pageID . ' to archive');
            }
        }

        if ($forceDelete) {
            foreach (ClassInfo::getValidSubClasses() as $subclass) {
                $tableName = singleton($subclass)->config()->get('table_name');

                if ($this->hasTable($tableName)) {
                    $this->query(sprintf('DELETE FROM %s WHERE ID = %s', $tableName, $pageID));
                    $this->query(sprintf('DELETE FROM %s_Live WHERE ID = %s', $tableName, $pageID));
                }
            }
        }

        $this->echoProgress();
    }

    /**
     * Runs an INSERT or UPDATE query on the provided table depending on if a
     * record with the ID matches.
     *
     * @param string $table
     * @param int $id
     * @param array $fields
     */
    public function runInsertOrUpdate($table, $id, $fields)
    {
        $existing = $this->query(sprintf(
            'SELECT COUNT(*) FROM %s WHERE ID = %s',
            $table,
            (int) $id
        ))->value();

        $set = implode(', ', array_map(function ($k, $v) {
            if (is_int($v)) {
                return "$k = $v";
            }

            return "$k = '$v'";
        }, array_keys($fields), $fields));

        if ($existing) {
            return $this->query(sprintf(
                'UPDATE %s SET %s WHERE ID = %s',
                $table,
                $set,
                $id
            ));
        } else {
            return $this->query(sprintf(
                'INSERT INTO %s SET ID = %s, %s',
                $table,
                $id,
                $set
            ));
        }
    }

    /**
     * Corrects broken file paths
     */
    public function updateFilePathLinks($table, $column)
    {
        $records = DB::query("SELECT ID, $column FROM $table WHERE $column LIKE '%assets/%'");

        foreach ($records as $record) {
            $id = $record['ID'];
            $content = $record[$column];
            $html = new HTML5();
            $doc = $html->loadHTML($content);

            $needsWrite = false;

            foreach ($doc->getElementsByTagName('img') as $img) {
                // if an image links to assets and is not rewritten to use a short code then we need to add the link
                // to the short code ID if we can find it.
                $src = $img->getAttribute('src');

                $shortcode = $img->getAttribute('data-shortcode');
                $shortcodeId = $img->getAttribute('data-id');

                if (!$shortcodeId && !$shortcode && $src) {
                    if (substr($src, 0, 8) === "\/assets\/" || substr($src, 0,7) === 'assets/') {
                        if (strpos($src, '_resampled/')) {

                            $parts = explode('/', $src);

                            $file = File::get()->filter([
                                'Filename:PartialMatch:nocase' => $parts[count($parts) -1]
                            ])->first();
                        } else {
                            $file = File::get()->filter('Filename:PartialMatch:nocase', $src)->first();
                        }

                        // find the correct ID for the file and attach it to the image if we can.
                        if ($file) {
                            $img->setAttribute('data-shortcode', 'image');
                            $img->setAttribute('data-id', $file->ID);

                            $needsWrite = true;
                        }
                    }
                } else if (strpos($src, '_resampled/') && $shortcodeId) {
                    $file = File::get()->byId($shortcodeId);

                    if ($file) {
                        $needsWrite = true;
                        $img->setAttribute('src', $file->getURL());
                    }
                }
            }

            if ($needsWrite) {
                DB::prepared_query("UPDATE \"$table\" SET \"$column\" = ? WHERE \"ID\" = ?", [
                    $html->saveHTML($doc),
                    $id
                ]);

                if ($this->verbose) {
                    $this->echoSuccess("Updated {$table}.{$column} ". $id);
                }
            }

            $this->echoProgress();
        }
    }

    /**
     * For things like invalid values in an enum you get a blank value. Not null
     * nor empty string '', so you basically can't pick it up. What this does is
     * loop over all the rows in the table and sets to a default value if it's
     * not set in PHP
     *
     * @param string $table
     * @param string $column
     * @param string $defaultValue
     */
    protected function setInvalidEnumValuesTo ($table, $column, $defaultValue)
    {
        if ($this->hasTable($table)) {
            $records = $this->performQuery("SELECT * FROM $table");

            foreach ($records as $row) {
                if (!$row[$column]) {
                    $this->performQuery(sprintf(
                        "UPDATE $table SET $column = '%s' WHERE ID = %s",
                        $defaultValue,
                        $row['ID']
                    ))->value();
                }

                $this->echoProgress();
            }
        }
    }

}
