<?php
namespace Stanford\GiftcardReward;

use \Exception;

class emLock
{

    const tableName    = "redcap_em_lock";
    const logTableName = "redcap_em_lock_log";
    static $error      = "";
    static $lockId;
    static $ts_start;

    /**
     * See if the requisite tables are in-place
     * @return boolean
     */
    public static function validate() {
        if (self::tablesExist()) {
            return true;
        } else {
            self::createTables();
            if (self::tablesExist()) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Check if tables for emLock exist
     * @return bool
     */
    public static function tablesExist() {
        $sql = "SHOW TABLES LIKE '" . self::tableName . "%';";
        $q = db_query($sql);
        if (db_num_rows($q) != 2) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Try to create the tables
     * @return bool
     */
    public static function createTables() {
        $sql = "create table redcap_em_lock
            (
                id int NOT NULL AUTO_INCREMENT,
                scope varchar(256) UNIQUE NOT NULL,
                creation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            )";
        $q = db_query($sql);
        $sql = "create table redcap_em_lock_log
            (
                id int NOT NULL AUTO_INCREMENT,
                lock_id int NOT NULL,
                creation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                duration_ms int,
                PRIMARY KEY (id)
            )";
        $q = db_query($sql);
        $sql = "alter table redcap_em_lock_log
            add constraint redcap_em_lock_log_redcap_em_lock_id_fk
                foreign key (lock_id) references redcap_em_lock (id)
                    on delete cascade";
        $q = db_query($sql);
        return true;
    }

    /**
     * Obtain a lock
     * @param $scope
     * @return bool success (if false, you must retry or abort)
     * @throws Exception
     */
    public static function lock($scope) {
        if (!self::validate()) throw new Exception("Unable to validate table schema");

        if (empty($scope)) {
            throw New Exception("Invalid SCOPE passed in");
        }

        self::$ts_start = microtime(true);

        $scope = FILTER_VAR($scope, FILTER_SANITIZE_STRING);

        // Verify there is a row to lock in the external_modules_log table
        $sql = "select * from " . self::tableName . " where scope = '$scope'";
        $q = db_query($sql);
        if (db_num_rows($q) == 0) {
            // First we must create a new row for this scope before we lock the record
            $sql = "INSERT INTO " . self::tableName . " (scope) " .
                "values ('$scope')";
            $q = db_query($sql);
        }

        db_query("SET AUTOCOMMIT=0");

        $sql = "select id from " . self::tableName ." where scope = '$scope' for update";
        $q = db_query($sql);

        self::$lockId = db_result($q, 0);

        // If we have a lock-timeout, the query will return db_errono=1205
        // Lock wait timeout exceeded; try restarting transaction
        if (db_errno() == 0) {
            return self::$lockId;
        } else {
            throw new Exception(db_error());
        }
    }


    /**
     * Commit or Rollback the Lock
     * @param boolean $success
     */
    public static function release($success = true) {

        if (empty(self::$lockId)) {
            // No need to unlock - already done
            return false;
        }

        $duration_ms = round((microtime(true) - self::$ts_start) * 1000, 0);

        $sql = "insert into " . self::logTableName . " (lock_id, duration_ms) " .
            "VALUES (" . self::$lockId . ", " . intval($duration_ms) . ")";

        db_query($sql);

        if ($success) {
            db_query("COMMIT");
        } else {
            db_query("ROLLBACK");
        }
        db_query("SET AUTOCOMMIT=1");
    }

}
