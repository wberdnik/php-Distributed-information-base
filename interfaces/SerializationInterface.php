<?php
namespace app\components\DBexchangePlan\interfaces;

/** Модель, поддерживающая этот интерфейс, региструется в плане обмена
 *
 * @author <wberdnik@gmail.com>
 */
interface SerializationInterface {
    
    
    /** Unmutable a entity name
    *
     * Функция возвращает уникальное имя, которое должно быть таким же для противоположного узла обмена
     * по нему происходит поиск соотвествия таблиц. Должно быть уникальным в пределах одной БД 
     */
    public static function ModelUniversalName(string $node):string;
    
    
    /** Запись ActiveRecord без регистрации в плане обмена
     * 
     * @param bool $runValidation - валидация
     * @param array $attributeNames list of attribute names that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @param bool $RegisterPlan - нужно ли регистрировать в плане обмена
     * @return bool  - успех/ошибка
     */
    public function pureSave(bool $runValidation = true,?array $attributeNames = null):bool;
    
    
    /** Сериализовать сущность
     * 
     * @param string $node
     * @return array 
     */
    public function encode(string $node):array;
    
    
    /** Принять сущность (десерилизовать, проверить наличие, при наличии правил - создать или обновить)
     * 
     * @param string $pack - JSON объект
     * @param string $node - от кого пришел пакет
     * @return SerializationInterface|null
     */
    public static function decode(array $pack, string $node = ''):?SerializationInterface;
    
    
    /** Глобальный идентификатор сущности
     * 
     * @return string - универсальная ссылка, из типа (UniversalName) и бизнес ключа
     */
    public function getULink(string $node): string;
    
    
    /** Значение бизнес-ключа
     * 
     * @param string $node
     * @return string|null
     */
    public function getBusinesKeyValue(string $node): ?string;
    
    
    /** Переопредение для управления направленим обмена (есть ли регистрация с этой стороны)
     * @return array of string Список получателей. Если null - все получатели из config
     */
    public static function shallTouchCustomers(): ?array;
    
    
    /** Название поля таблицы БД, где хранится бизнес-ключ
     * 
     * @return string - имя атрибута бизнес ключа для участника, Если $node = '', то любой первый
     */
    
    
    public static function getColumnBusinesKey(string $node = ''): string;
    
    
    /** Действие при удалении сущности на другой стороне 
    *
     * Пришли сведения, что записи удалены  удаленно(remote)
     * Решите сами что с этим делать
     */
    public static function onRemoteDeletes(array $uids, string $node): void;
}
