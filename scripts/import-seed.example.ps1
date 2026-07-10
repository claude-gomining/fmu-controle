$env:MONGODB_URI = "mongodb+srv://USUARIO:SENHA@HOST.mongodb.net/?appName=Atividades"
$env:MONGODB_DATABASE = "activity"

mongoimport --uri $env:MONGODB_URI --db $env:MONGODB_DATABASE --collection fmu_activity_control --file data/fmu_activity_control.seed.json --jsonArray
mongoimport --uri $env:MONGODB_URI --db $env:MONGODB_DATABASE --collection fmu_user_control --file data/fmu_user_control.seed.json --jsonArray
