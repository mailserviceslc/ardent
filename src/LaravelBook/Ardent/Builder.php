<?php namespace LaravelBook\Ardent;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Collection;

class Builder extends \Illuminate\Database\Eloquent\Builder {

	/**
	 * Forces the behavior of findOrFail in very find method - throwing a {@link ModelNotFoundException}
	 * when the model is not found.
	 *
	 * @var bool
	 */
	public $throwOnFind = false;

	public function find($id, $columns = array('*')) {
		return $this->maybeFail('find', func_get_args());
	}

	public function first($columns = array('*')) {
		return $this->maybeFail('first', func_get_args());
	}

	/**
	 * Will test if it should run a normal method or its "orFail" version, and behave accordingly.
	 * @param string $method called method
	 * @param array  $args   given arguments
	 * @return mixed
	 */
	protected function maybeFail($method, $args) {
		$debug  = debug_backtrace(false);
		$orFail = $method.'OrFail';
		$func   = ($this->throwOnFind && $debug[2]['function'] != $orFail)? array($this, $orFail) : "parent::$method";
		return call_user_func_array($func, $args);
	}

  /**
   * Eagerly load the relationship on a set of models.
   *
   * @param  array $models
   * @param  string $name
   * @param  \Closure|array $constraints
   * @return array
   */
  protected function loadRelation(array $models, $name, Closure $constraints)
  {
    // First we will "back up" the existing where conditions on the query so we can
    // add our eager constraints. Then we will merge the wheres that were on the
    // query back to it in order that any where conditions might be specified.
    $relation = $this->getRelation($name);

    // In order for a MorphTo relationships to eagerload, we need to know what kind of
    // related models we are dealing with, this information is not captured in the relationship
    // and must be gathered from the actual models
    if ($relation instanceof MorphTo) {
      // First we collect the necessary information that we need
      $relationForType = $modelsForType = array();
      foreach ($models as $model) {
        $relation = $this->getRelation($name, $model);
        $type = get_class($relation->getModel());

        $relationForType[$type] = $relation;
        $modelsForType[$type][] = $model;
      }

      $results = array();
      foreach ($relationForType as $type => $relation) {
        $typeModels = $modelsForType[$type];

        $typeModels = $relation->initRelation($typeModels, $name);

        if (array_key_exists($type, $constraints)) {
          $typeConstraints = $constraints[$type];
        } else {
          $typeConstraints = function ($q) {
            return $q;
          };
        }

        $newResults = $this->processEagerloads($typeModels, $typeConstraints, $relation);

        $results = array_merge($results, $newResults->all());
      }

      $results = new Collection($results);
    } else {
      $models = $relation->initRelation($models, $name);

      $results = $this->processEagerloads($models, $constraints, $relation);
    }

    return $relation->match($models, $results, $name);
  }

  protected function processEagerloads($models, $constraints, $relation)
  {
    $relation->addEagerConstraints($models);

    call_user_func($constraints, $relation);

    // Once we have the results, we just match those back up to their parent models
    // using the relationship instance. Then we just return the finished arrays
    // of models which have been eagerly hydrated and are readied for return.
    return $relation->get();
  }

  /**
   * Get the relation instance for the given relation name.
   *
   * @param  string $relation
   * @return \Illuminate\Database\Eloquent\Relations\Relation
   */
  public function getRelation($relation, $model = null)
  {
    $model = $model ? : $this->getModel();

    // We want to run a relationship query without any constrains so that we will
    // not have to remove these where clauses manually which gets really hacky
    // and is error prone while we remove the developer's own where clauses.
    $query = Relation::noConstraints(
      function () use ($model, $relation) {
        return $model->$relation();
      }
    );

    $nested = $this->nestedRelations($relation);

    // If there are nested relationships set on the query, we will put those onto
    // the query instances so that they can be handled after this relationship
    // is loaded. In this way they will all trickle down as they are loaded.
    if (count($nested) > 0) {
      $query->getQuery()->with($nested);
    }

    return $query;
  }
}