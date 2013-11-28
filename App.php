<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Nuiz
 * Date: 27/11/2556
 * Time: 23:21 น.
 * To change this template use File | Settings | File Templates.
 */

session_start();
date_default_timezone_set("Asia/Bangkok");

ini_set('include_path', ini_get('include_path').';Classes/');
include_once("Classes/PHPExcel.php");
class App {
    protected static $_db = null, $_hospitals = null;
    public static function db(){
        if(is_null(self::$_db)){
            //self::$_db = new PDO('mysql:host=localhost;dbname=admin_drugprice;charset=utf8', 'admin_drugprice', '111111');
            self::$_db = new PDO('mysql:host=localhost;dbname=drugprice;charset=utf8', 'root', '111111');
            self::$_db->query("SET character_set_client=utf8");
            self::$_db->query("SET character_set_connection=utf8");
        }
        return self::$_db;
    }

    public static function getUser(){
        return isset($_SESSION["user"])? $_SESSION["user"]: null;
    }

    public static function setUser($user){
        $_SESSION["user"] = $user;
    }

    public static function logout(){
        unset($_SESSION["user"]);
    }

    public static function login($email, $password){
        $pdo = self::db();
        $st = $pdo->prepare("SELECT * FROM user WHERE email=:email AND deleted!=1");
        $st->execute(array("email"=> $email));
        if($st->rowCount() == 0)
            return false;
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if($user["password"]!=md5($password)){
            return false;
        }
        self::setUser($user);
        return $user;
    }

    public static function isLogin(){
        return !is_null(self::getUser());
    }

    public static function isAdmin(){
        $login = self::isLogin();
        $user = self::getUser();
        return $login && $user["iduser"]==1;
    }

    public static function users(){
        $pdo = self::db();
        $result = $pdo->query("SELECT * FROM user");
        return $result->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function allDrug($input = null){
        $pdo = self::db();
        if(!is_null($input) && count($input)>0){
            $sql = "SELECT * FROM drug";
            $where = array();
            $param = array();

            if(isset($input["hospitalId"]) && !empty($input["hospitalId"])){
                $where[] = "hospitalId=:hospitalId";
                $param["hospitalId"] = $input["hospitalId"];
            }
            if(isset($input["date_start"]) && !empty($input["date_start"])){
                $where[] = "date_start>=:date_start";
                $buffer = new DateTime($input["date_start"]);
                $param["date_start"] = $buffer->format("Y-m-d");
            }
            if(isset($input["date_end"]) && !empty($input["date_end"])){
                $where[] = "date_end<=:date_end";
                $buffer = new DateTime($input["date_end"]);
                $param["date_end"] = $buffer->format("Y-m-d");
            }
            if(isset($input["name"]) && !empty($input["name"])){
                $where[] = "name LIKE :name";
                $param["name"] = '%'.str_replace(" ", "%", $input["name"]).'%';
            }
            if(isset($input["total_money"]) && !empty($input["total_money"])){
                $where[] = "CAST(total_money as DECIMAL(10,2))>=CAST(:total_money as DECIMAL(10,2))";
                $param["total_money"] = $input["total_money"];
            }

            if(count($where)>0){
                $sql .= " WHERE ".implode(" AND ", $where);
            }
            $st = $pdo->prepare($sql);
            $result = $st->execute($param);
            if(!$result){
                die(print_r($st->errorInfo(), true));
            }
            $result = $st;
        }
        else{
            $result = $pdo->query("SELECT * FROM drug");
            if(!$result){
                die(print_r($pdo->errorInfo(), true));
            }
        }
        $data = $result->fetchAll(PDO::FETCH_ASSOC);
        foreach($data as $key => $value){
            $data[$key]['hospital_name'] = self::getHospitalName($value["hospitalId"]);
        }
        return $data;
    }

    public static function importDrug($path, $name, $userId = 0){
        $objReader = PHPExcel_IOFactory::createReader('Excel2007');
        $objReader->setReadDataOnly(true);

        $objPHPExcel = $objReader->load($path);
        $objWorksheet = $objPHPExcel->getActiveSheet();

        $highestRow = $objWorksheet->getHighestRow(); // e.g. 10
        $highestColumn = $objWorksheet->getHighestColumn(); // e.g 'F'

        $highestColumnIndex = PHPExcel_Cell::columnIndexFromString($highestColumn); // e.g. 5

        $data = array();
        for ($row = 7; $row <= $highestRow; ++$row) {
            $dateStart = date("Y-m-d", ($objWorksheet->getCellByColumnAndRow(1, $row)->getValue()-25569) * 86400);
            $dateEnd = date("Y-m-d", ($objWorksheet->getCellByColumnAndRow(2, $row)->getValue()-25569) * 86400);

            $hospId = self::getHospitalId($objWorksheet->getCellByColumnAndRow(0, $row)->getValue());
            $data[] = array(
                "hospitalId"=> $hospId,
                "date_start"=> $dateStart,
                "date_end"=> $dateEnd,
                "code"=> $objWorksheet->getCellByColumnAndRow(3, $row)->getValue(),
                "name"=> $objWorksheet->getCellByColumnAndRow(4, $row)->getValue(),
                "type"=> $objWorksheet->getCellByColumnAndRow(5, $row)->getValue(),
                "content"=> $objWorksheet->getCellByColumnAndRow(6, $row)->getValue(),
                "qtc"=> $objWorksheet->getCellByColumnAndRow(7, $row)->getValue(),
                "pack"=> $objWorksheet->getCellByColumnAndRow(8, $row)->getValue(),
                "price"=> $objWorksheet->getCellByColumnAndRow(9, $row)->getValue(),
                "size"=> $objWorksheet->getCellByColumnAndRow(10, $row)->getValue(),
                "company"=> $objWorksheet->getCellByColumnAndRow(15, $row)->getValue(),
                "total_money"=> $objWorksheet->getCellByColumnAndRow(17, $row)->getValue(),
                "budget_type"=> $objWorksheet->getCellByColumnAndRow(18, $row)->getValue(),
                "userId"=> $userId
            );
        }

        $pdo = self::db();
        $columns = array('hospitalId','date_start','date_end','code','name','type','content','qtc','pack','price','size','company','total_money','budget_type','userId');
        $column_list = join(',', $columns);
        $param_list = join(',', array_map(function($col) { return ":$col"; }, $columns));
        $sql = "INSERT INTO `drug` ({$column_list}) VALUES ({$param_list})";
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            die(print_r($pdo->errorInfo(), true));
        }
        foreach($data as $key => $value){
            $param_values = array_intersect_key($value, array_flip($columns));
            $status = $statement->execute($param_values);
            if ($status === false) {
                die(print_r($statement->errorInfo(), true));
            }
        }

        move_uploaded_file($path, "docs/".$name);
    }

    public static function getHospitalId($name){
        $name = trim($name);
        $hospitals = self::hospitals();
        foreach($hospitals as $key => $value){
            if($value["hospital_name"]==$name){
                return $value["idhospital"];
            }
        }
        self::addHospital($name);
        return self::getHospitalId($name);
    }

    public static function addHospital($name){
        $name = trim($name);
        $pdo = self::db();
        $st = $pdo->prepare("SELECT * FROM hospital WHERE hospital_name=:hospital_name");
        $st->execute(array("hospital_name"=> $name));
        if($st->rowCount() > 0){
            $hospital = $st->fetch(PDO::FETCH_ASSOC);
            if($hospital["deleted"]==1){
                $st = $pdo->prepare("UPDATE hospital SET deleted=0 WHERE idhospital=:idhospital");
                $st->execute(array("idhospital"=> $hospital["idhospital"]));
            }
            return $hospital["id"];
        }
        $st = $pdo->prepare("INSERT INTO hospital(hospital_name) VALUES(:hospital_name)");
        $st->execute(array("hospital_name"=> $name));
        self::refreshHospitals();
        return $pdo->lastInsertId("idhospital");
    }

    public static function getHospitalName($id){
        $hospitals = self::hospitals();
        foreach($hospitals as $key => $value){
            if($value["idhospital"]==$id){
                return $value["hospital_name"];
            }
        }
    }

    public static function hospitals(){
        if(self::$_hospitals==null){
            self::$_hospitals = self::getHospitals();
        }
        return self::$_hospitals;
    }

    public static function getHospitals(){
        $pdo = self::db();
        $result = $pdo->query("SELECT * FROM hospital");
        return $result->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function refreshHospitals(){
        $pdo = self::db();
        $result = $pdo->query("SELECT * FROM hospital");
        self::$_hospitals = $result->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function addUser($attr){
        $pdo = self::db();
        $st = $pdo->prepare("INSERT INTO user (first_name, last_name, email, password, hospitalId) VALUES(:first_name, :last_name, :email, :password, :hospitalId)");
        $result = $st->execute(array(
            "first_name"=> $attr["first_name"],
            "last_name"=> $attr["last_name"],
            "email"=> $attr["email"],
            "password"=> md5($attr["password"]),
            "hospitalId"=> $attr["hospitalId"]
        ));
        if(!$result){
            print_r($st->errorInfo());
            return false;
        }
        return true;
    }

    public static function getStats($input = null){
        $pdo = self::db();
        if(!is_null($input) && count($input)>0){
            $sql = "SELECT AVG(CAST(price as DECIMAL(10))) as avg, MIN(CAST(price as DECIMAL(10))) as min, MAX(CAST(price as DECIMAL(10))) as max FROM drug";
            $where = array();
            $param = array();

            if(isset($input["hospitalId"]) && !empty($input["hospitalId"])){
                $where[] = "hospitalId=:hospitalId";
                $param["hospitalId"] = $input["hospitalId"];
            }
            if(isset($input["date_start"]) && !empty($input["date_start"])){
                $where[] = "date_start>=:date_start";
                $buffer = new DateTime($input["date_start"]);
                $param["date_start"] = $buffer->format("Y-m-d");
            }
            if(isset($input["date_end"]) && !empty($input["date_end"])){
                $where[] = "date_end<=:date_end";
                $buffer = new DateTime($input["date_end"]);
                $param["date_end"] = $buffer->format("Y-m-d");
            }
            if(isset($input["name"]) && !empty($input["name"])){
                $where[] = "name LIKE :name";
                $param["name"] = '%'.str_replace(" ", "%", $input["name"]).'%';
            }
            if(isset($input["total_money"]) && !empty($input["total_money"])){
                $where[] = "CAST(total_money as DECIMAL(10,2))>=CAST(:total_money as DECIMAL(10,2))";
                $param["total_money"] = $input["total_money"];
            }

            if(count($where)>0){
                $sql .= " WHERE ".implode(" AND ", $where);
            }
            $st = $pdo->prepare($sql);
            $result = $st->execute($param);
            if(!$result){
                die(print_r($st->errorInfo(), true));
            }
            $result = $st;
        }
        else{
            $result = $pdo->query("SELECT AVG(CAST(price as DECIMAL(10))) as avg, MIN(CAST(price as DECIMAL(10))) as min, MAX(CAST(price as DECIMAL(10))) as max FROM drug");
            if(!$result){
                die(print_r($pdo->errorInfo(), true));
            }
        }
        return $result->fetch(PDO::FETCH_ASSOC);
    }

    public static function updateUser($id, $attr){
        $pdo = self::db();
        $query = 'UPDATE user SET';
        $values = array();

        unset($attr["iduser"]);
        unset($attr["password"]);

        foreach ($attr as $name => $value) {
            $query .= ' '.$name.' = :'.$name.',';
            $values[$name] = $value;
        }
        $query = substr($query, 0, -1)." WHERE iduser=:id";
        $values["id"] = $id;
        $st = $pdo->prepare($query);
        if(!$st->execute($values)){
            $errorInfo = $st->errorInfo();
            throw new Exception($errorInfo[2]);
        }
    }
    public static function deleteUser($id){
        $pdo = self::db();
        $st = $pdo->prepare("UPDATE user SET deleted=1 WHERE iduser=:iduser");
        if(!$st->execute(array("iduser"=> $id))){
            $errorInfo = $st->errorInfo();
            throw new Exception($errorInfo[2]);
        }
    }

    public static function updateHospital($id, $attr){
        $pdo = self::db();
        $query = 'UPDATE hospital SET';
        $values = array();

        foreach ($attr as $name => $value) {
            $query .= ' '.$name.' = :'.$name.',';
            $values[$name] = $value;
        }
        $query = substr($query, 0, -1)." WHERE idhospital=:id";
        $values["id"] = $id;
        $st = $pdo->prepare($query);
        if(!$st->execute($values)){
            $errorInfo = $st->errorInfo();
            throw new Exception($errorInfo[2]);
        }
    }

    public static function deleteHospital($id){
        $pdo = self::db();
        $st = $pdo->prepare("UPDATE hospital SET deleted=1 WHERE idhospital=:idhospital");
        if(!$st->execute(array("idhospital"=> $id))){
            $errorInfo = $st->errorInfo();
            throw new Exception($errorInfo[2]);
        }
    }

    public static function filterDeleted($data){
        $res = array();
        if(is_array($data)){
            foreach($data as $key => $value){
                if(@$value["deleted"]!=1)
                    $res[] = $value;
            }
        }
        return $res;
    }

    public static function changePassword($id, $newPassword){
        $pdo = self::db();
        $query = 'UPDATE user SET password=:password WHERE iduser=:id';
        $st = $pdo->prepare($query);
        if(!$st->execute(array("id"=> $id, "password"=> md5($newPassword)))){
            $errorInfo = $st->errorInfo();
            throw new Exception($errorInfo[2]);
        }
    }
}