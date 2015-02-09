<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Created by PhpStorm.
 * User: rem
 * Date: 03.12.2014
 * Time: 18:22
 */
class DbCache extends Component
{
    /**
     * @var int
     */
    private $time = 300;

    /**
     * @var string
     */
    private $key;

    /**
     * @var \yii\db\ActiveQuery
     */
    private $query;

    /**
     * @var string
     */
    private $class;

    /**
     * @var bool
     */
    private $asArray = false;

    /**
     * @var \app\abstracts\ActiveRecord
     */
    private $model;

    /**
     * @var
     */
    private $withFastData;

    /**
     * @param $query
     * @param null $time
     * @return $this
     */
    public function get($query, $time = null)
    {
        if(is_string($query)){
            $this->key = $query;
            $modelName = explode(':', $query);
            if($modelName){
                $modelName = str_replace('_', '\\', $modelName[0]);
                if(class_exists($modelName)){
                    $this->model = new $modelName();
                    $this->class = $modelName;
                }
            }
        }elseif($query instanceof ActiveQuery){
            $fastKey = BaseModel::FAST_WITH_KEY;

            if(is_array($query->with)){
                if(in_array($fastKey, $query->with)){
                    $getter = 'get'.ucfirst($fastKey);
                    $this->withFastData = $this->model->$getter();
                    unset($query->with[$fastKey]);
                }elseif(isset($query->with[$fastKey])){
                    $this->withFastData = $query->with[$fastKey];
                    unset($query->with[$fastKey]);
                }
            }elseif($query->with == $fastKey){
                $getter = 'get'.ucfirst($fastKey);
                $this->withFastData = $this->model->$getter();
                unset($query->with[$fastKey]);
            }

            $this->key = $this->createKey($query);
            $modelName = $query->modelClass;
            $this->model = new $modelName();
            $this->query = $query->asArray();
            $this->class = $modelName;
        }

        if($time !== null)
            $this->time = $time;

        return $this;
    }

    /**
     * @return \app\components\DbCache
     */
    public function asArray()
    {
        $this->asArray = true;
        return $this;
    }

    /**
     * @param $query
     * @param null $time
     * @return \app\abstracts\BaseModel|null
     */
    public function getOne($query, $time = null)
    {
        return $this->get($query, $time)->one();
    }

    /**
     * @param $query
     * @param null $time
     * @return \app\abstracts\BaseModel[]|null
     */
    public function getAll($query, $time = null)
    {
        return $this->get($query, $time)->all();
    }

    public function set($key, $object, $time = null)
    {
        if($time === null)
            $time = $this->time;

        if($key instanceof ActiveQuery)
            $key = $this->createKey($key);

        $this->getData()->set($key, json_encode($object));
        if($time > 0)
            $this->getData()->expire($key, $time);
    }

    /**
     * @return \app\abstracts\BaseModel|null
     */
    public function one()
    {
        $this->key = $this->key.'_one';
        $data = $this->getData()->get($this->key);

        if(!$data){
            if($this->query instanceof ActiveQuery){
                $data = $this->query->one();
            }else{
                $data = $this->query;
            }

            $this->set($this->key, $data, $this->time);
        }

        if($this->asArray){
            $this->clear();
            return is_string($data) ? json_decode($data, true) : $data;
        }

        $class = $this->class;
        $with = $this->query->with;

        $this->clear();
        return $this->createObject($data, $class, $with);
    }

    /**
     * @return \app\abstracts\BaseModel[]
     */
    public function all()
    {
        $data = [];
        $fastData = [];
        $limit = -1;
        $this->key = $this->key.'_all';

        if($this->withFastData !== null){
            $fd = $this->withFastData;
            $key = isset($fd['key']) ? (string)$fd['key'] : $this->key;
            $limit = isset($fd['limit']) ? (int)$fd['limit'] : (int)$this->query->limit;

            if($limit === 0)
                $limit = -1;

            $data = $this->getData()->findAll($key);

            if($data !== null){
                foreach($data as $num => $json){
                    if($limit === 0)
                        break;

                    $json = json_decode($json, true);
                    $key = isset($json['id']) ? $json['id'] : $num;

                    $fastData[$key] = $json;

                    if($limit !== -1)
                        $limit--;
                }

                if($this->query->orderBy){
                    ksort($fastData);
                    if(end($this->query->orderBy) == SORT_DESC)
                        $fastData = array_reverse($fastData);
                }
            }
        }

        if($limit === -1 || $limit > 0){
            $data = $this->getData()->get($this->key);

            if(!$data){
                if($this->query instanceof ActiveQuery){
                    if($limit > 0)
                        $this->query->limit($limit);

                    $data = $this->query->all();
                }
                else
                    $data = $this->query;

                $this->set($this->key, $data, $this->time);
            }
        }

        $objects = [];
        if(is_string($data))
            $data = json_decode($data, true);

        if($fastData){
            if(!is_array($data))
                $data = [];
            $data = array_merge($fastData, $data);
        }

        if($data){
            if($this->asArray){
                $this->clear();
                return $data;
            }

            $class = $this->class;
            if(is_array($data)){
                foreach($data as $val){
                    $objects[] = $this->createObject($val, $class, $this->query->with);
                }
            }else{
                $objects[] = $data;
            }
        }

        $this->clear();

        return $objects;
    }

    public function sum($q)
    {
        $this->key = $this->key.'_sum';
        $data = $this->getData()->get($this->key);

        if(!$data){
            $data = $this->query->sum($q);
            $this->set($this->key, floatval($data), $this->time);
        }

        $this->clear();

        return floatval($data);
    }

    public function update($query, $time = null)
    {
        if(is_string($query)){
            $this->getData()->remove($query);
        }elseif($query instanceof ActiveQuery){
            $this->get($query, $time);
            $this->getData()->remove($this->key.'_one');
            $this->getData()->remove($this->key.'_all');
            $this->getData()->remove($this->key.'_sum');
        }
    }

    public function updateById(BaseModel $entity, $id)
    {
        $query = $entity::find()->where(['id' => $id]);
        $this->update($query);
    }

    public function getKey()
    {
        return $this->key;
    }

    private function createObject($data, $class = null, $with = null)
    {
        if($class === null)
            $class = $this->model;

        if(!$class)
            return null;

        if(is_string($data))
            $data = json_decode($data, true);

        if(!$data)
            return null;

        $model = $class::instantiate($data);
        $class::populateRecord($model, $data);

        if($with !== null){
            if(is_string($with))
                $with = [$with];

            foreach($with as $relation){
                $getter = 'get'.ucfirst($relation);

                if(isset($data[$relation]) && $data[$relation] !== null && method_exists($model, $getter)){
                    $query = $model->$getter();

                    if($query && $class = $query->modelClass){
                        $relationAttributes = $data[$relation];

                        if(isset($relationAttributes[0])){
                            $relatedModels = [];

                            foreach($relationAttributes as $attributes){
                                $relatedModel = $class::instantiate($attributes);
                                $class::populateRecord($relatedModel, $attributes);

                                $relatedModels[] = $relatedModel;
                            }

                            $model->populateRelation($relation, $relatedModels);
                        }else{
                            $relatedModel = $class::instantiate($relationAttributes);
                            $class::populateRecord($relatedModel, $relationAttributes);

                            $model->populateRelation($relation, $relatedModel);
                        }
                    }
                }
            }
        }

        return $model;
    }

    private function createKey(ActiveQuery $query)
    {
        $command = $query->createCommand();
        $sql = $command->sql;
        $params = $command->params;

        $key = (str_replace(' ', '_', $sql));
        if($params){
            foreach($params as $name => $value){
                $key .= '_'.$name.':'.$value;
            }
        }

        $key = $this->keyAdd($key, $query->with);
        return $this->keyAdd($key, $this->withFastData);
    }

    /**
     * @return \app\components\FastData
     */
    private function getData()
    {
        return Yii::$app->fastData;
    }

    private function keyAdd($key, $params, $implode = false)
    {
        if(!$params)
            return $key;

        if(is_array($params)){
            if($implode){
                if(count($params) <= 10)
                    $key .= implode(':', $params);
                else
                    $key .= $params[0].':'.$params[1].':'.$params[2].':'.$params[3].':'.$params[4].':'.end($params);
            }else{
                foreach($params as $name => $value){
                    if(is_array($value)){
                        foreach($value as $n => $val){
                            if(is_array($val)){
                                if(count($val) <= 10){
                                    foreach($val as $v){
                                        if(is_array($v)){
                                            if(count($v) <= 10)
                                                $key .= $n.'.'.implode('.', $v);
                                            else
                                                $key .= $n.'.'.$v[0].'.'.$v[1].'.'.$v[2].'.'.$v[3].'.'.$v[4].'.'.end($v);
                                        }
                                        else
                                            $key .= $n.'.'.$v;
                                    }
                                }else{
                                    $key .= $n.'.'.$val[0].'.'.$val[1].'.'.$val[2].'.'.$val[3].'.'.$val[4].'.'.end($val);
                                }
                            }else{
                                $key .= $n.'.'.$val;
                            }
                        }
                    }else{
                        $key .= $name.'.'.$value;
                    }
                }
            }
        }else{
            $key .= $params;
        }

        return $key.':';
    }

    private function clear()
    {
        $this->query = null;
        $this->withFastData = null;
        $this->asArray = false;
    }
}
