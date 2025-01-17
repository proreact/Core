<?php

namespace Core;

use Carbon\Carbon;
use Core\Exceptions\External;

class ActiveRecord
{
    protected static $_table;
    protected static $_typeMap;
    protected $_modified = [];
    protected $id;

    /**
     * @param $id
     * @return static|false
     * @throws \Exception
     */
    protected static function getById($id)
    {
        $sql = "SELECT * FROM `" . static::$_table . "` WHERE id = :id";
        $pdo = DB::get();
        $stmt = $pdo->prepare($sql);

        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        $obj = false;
        if ($row) {
            $obj = static::createFromArray($row);
        }

        return $obj;
    }

    /**
     * @param $ids
     * @return static[]
     * @throws \Exception
     */
    protected static function getByIdMulti($ids)
    {
        if (!$ids) {
            return [];
        }

        $idCnt = count($ids);
        $inData = str_repeat('?,',$idCnt);
        $inData = mb_substr($inData,0, -1);

        $sql = "SELECT * FROM `" . static::$_table . "` WHERE id IN($inData)";
        $stmt = DB::get()->prepare($sql);
        $stmt->execute($ids);

        $objs = [];
        while ($data = $stmt->fetch()) {
            $objs[] = static::createFromArray($data);
        }

        return $objs;
    }

    /**
     * @param $sql
     * @param array $params
     * @return static[]
     * @throws \Exception
     */
    protected static function getBySql($sql, $params = [])
    {
        $objs = [];

        $stmt = DB::get()->prepare($sql);
        $stmt->execute($params);

        while ($data = $stmt->fetch()) {
            $objs[] = static::createFromArray($data);
        }

        return $objs;
    }

    /**
     * @param string $sql
     * @param array $params
     * @return static|false
     * @throws \Exception
     */
    protected static function getBySqlSingle($sql, $params = [])
    {
        $objs = static::getBySql($sql, $params);

        if (!$objs) {
            return false;
        }

        return reset ($objs);
    }

    /**
     * @param $data
     * @return static
     */
    public static function createFromArray($data)
    {
        $obj = new static();
        foreach ($data as $key => $value) {
            if (property_exists($obj, $key)) {
                $type = static::$_typeMap[$key] ?? null;
                if ($value !== null) {
                    $value = match ($type) {
                        'dt', 'datetime' => Carbon::createFromFormat("Y-m-d H:i:s", $value, 'UTC'),
                        'json', 'json-object' => json_decode($value, flags: JSON_THROW_ON_ERROR),
                        'json-assoc' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
                        default => $value
                    };
                }

                $obj->$key = $value;
            }
        }

        return $obj;
    }

    /** Set a field
     * Use this to mark a field as changed
     *
     * @param $field
     * @param $value
     */
    protected function set($field, $value)
    {
        $this->$field = $value;
        $this->_modified[$field] = true;
    }

    /** Save
     * Updates record if id is set else a new row will be inserted
     *
     * @return bool
     * @throws \Exception
     */
    protected function save()
    {
        if (!$this->_modified) {
            return true;
        }

        $pdo = DB::get();
        $updatedFields = array_keys($this->_modified);
        if ($this->id) {
            $sql = "UPDATE `" . static::$_table . "` SET ";
            $fields = [];
            $values = [];
            foreach ($updatedFields as $field) {
                $fields[] = "`$field`=:$field";
                $values[$field] = $this->$field;
            }
            $sql .= implode(',', $fields);
            $sql .= " WHERE id = :id";
            $values['id'] = $this->id;
        } else {
            $sql = "INSERT INTO `" . static::$_table . "`";
            $placeholders = [];
            $values = [];
            foreach ($updatedFields as $field) {
                $placeholders[] = ":$field";
                $values[$field] =  $this->$field;
            }
            $escape = function(&$value) {
                $value = "`$value`";
            };
            array_walk($updatedFields, $escape);
            $sql .= "(" . implode(',', $updatedFields) . ") ";
            $sql .= "VALUES (" . implode(',', $placeholders) . ")";
        }

        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $values);
        $suc = $stmt->execute();

        if (!$this->id) {
            $this->id = $pdo->lastInsertId();
        }

        return $suc;
    }

    private function bindParams($stmt, $values)
    {
        foreach ($values as $field => $value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $type = static::$_typeMap[$field]?? null;
            switch ($type) {
                case null:
                    $stmt->bindValue(":$field", $value);
                    break;
                case 'dt':
                    if ($value !== null) {
                        $value->setTimezone('UTC');
                        $stmt->bindValue(":$field", $value->format('Y-m-d H:i:s'));
                    } else {
                        $stmt->bindValue(":$field", $value);
                    }
                    break;
                case 'bit':
                    $stmt->bindValue(":$field", (int)$value, \PDO::PARAM_INT);
                    break;
                case 'json':
                    $value = json_encode($value);
                    if ($value === false) {
                        throw new External("Error converting $field to json", 500);
                    }
                    $stmt->bindValue(":$field", $value);
                    break;
                default:
                    throw new \Exception("Unknown field type " . static::$_typeMap[$field] . " for $field");
            }
        }
    }
}
