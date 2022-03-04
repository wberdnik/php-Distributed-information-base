# php-Distributed-information-base

The Data exchange component for replication entityes between yii2 sites with pattern similarly 1C:Distributed information base

ExchangePlan
------------

ExchangePlan.php extending the ActiveRecord class is a Model to replicate changes and exchanges entityes. One implements ExchangeInterface with methoods for
registration of changes(touch, multiTouch), procesing a exchage packet (fetch, apply), acknowledgment (acknowledge), other (addExchError)

SerializableActiveRecord
------------------------

SerializableActiveRecord.php extending the ActiveRecord class is a parent class for entity models who participate in exchange. This class implements the SerializationInterface. Child classes should configure the lists of attributes to be passed and how to serialize them.

