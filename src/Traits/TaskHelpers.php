<?php

namespace Wilr\SilverStripe\Tasker\Traits;

use SilverStripe\ORM\DB;
use SilverStripe\Core\Convert;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
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
    public function migrateTableToExistingTable($tableName, $newClass, $mapping = [], $callback = null)
    {
        if ($this->hasTable($tableName)) {
            $data = DB::query("SELECT * FROM $tableName");

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
                        // $this->echoWarning('Cannot find '. $tableName.'#'. $record['ID'] . ' to migrate to '. $newClass);

                        continue;
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
        $tables = DB::table_list();

        return (isset($tables[strtolower($tableName)]));
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
        $result = DB::query(sprintf(
            "SHOW COLUMNS FROM `%s` LIKE '%s'",
            $table,
            $col
        ))->value();

        if ($result) {
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
}
