<?php

namespace Sevming\LaravelOptimisticLock;

use Illuminate\Database\Eloquent\Builder;

trait OptimisticLock
{
    protected $optimLock = false;

    /**
     * @return $this
     */
    public function enableLock(): self
    {
        $this->optimLock = true;
        return $this;
    }

    /**
     * @return $this
     */
    public function disableLock(): self
    {
        $this->optimLock = false;
        return $this;
    }

    /**
     * Perform a model update operation.
     *
     * @param Builder $query
     *
     * @return bool
     */
    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $dirty = $this->handleOptimisticLock($query, $dirty);
            $affected = $this->setKeysForSaveQuery($query)->update($dirty);
            if ($this->optimLock && 0 === $affected) {
                throw new OptimisticLockException('Model has been changed during update.');
            }

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * @return string
     */
    protected function getLockField(): string
    {
        return property_exists($this, 'lockField') && isset($this->lockField) ? $this->lockField : 'lock_version';
    }

    /**
     * @param Builder $query
     * @param array   $dirty
     *
     * @return array
     */
    protected function handleOptimisticLock(Builder $query, array $dirty): array
    {
        if ($this->optimLock) {
            $lockField = $this->getLockField();
            $currentLockVersion = $this->getAttribute($lockField);
            $query->where($lockField, '=', $currentLockVersion);
            $this->setAttribute($lockField, $newLockVersion = $currentLockVersion + 1);
            $dirty[$lockField] = $newLockVersion;
        }

        return $dirty;
    }
}