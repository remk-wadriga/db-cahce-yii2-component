# db-cahce-yii2-component
#Yii2 component, who cached the sql-requests results and then create models ftom this results (array).

*setup:

'dbCache' => [
    'class' => 'app\components\DbCache'
],



*Find a user (with its posts and comments) by the ID:

$query = User::find()
              ->with(['posts', 'comments'])
              ->where(['user_id' => $userId]);

$user = Yii::$app->dbCache->find($query, 3600)->one();


*Find all users (with its posts and comments) by the ID:

$query = User::find()
              ->with(['posts', 'comments']);

$users = Yii::$app->dbCache->find($query, 3600)->all();

