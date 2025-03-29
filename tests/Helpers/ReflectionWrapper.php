<?php
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpDocMissingThrowsInspection */
/** @noinspection MagicMethodsValidityInspection */
declare(strict_types=1);

/**
 * Class ReflectionWrapper
 *
 * This class provides a convenient way to access and manipulate private or protected properties and methods of an object is a natural way.
 *
 * Examples:
 * $obj = ReflectionWrapper::for($object); // $obj->privateProperty is now accessible
 * ReflectionWrapper::for($object)->propertyName;
 * ReflectionWrapper::for($object)->propertyName = $value;
 * ReflectionWrapper::for($object)->methodName($arg1, $arg2);
 * ReflectionWrapper::for($object)->callStatic('methodName', $arg1, $arg2);
 * ReflectionWrapper::for('ClassName')->staticMethodName($arg1, $arg2); // using classname string
 *
 */

class ReflectionWrapper
{

    private object          $object;

    private ReflectionClass $reflection;

    #region Constructor

    /**
     * Creates a new instance of the class.
     *
     * @param mixed $objectOrClass The object or class name to create an instance of.
     *
     * @return self The newly created instance of the class.
     */
    public static function for(object|string $objectOrClass): ReflectionWrapper {
        return new self($objectOrClass);
    }


    /**
     * Constructor for the class.
     *
     * @param object|string $objectOrClass The object or class name to be used.
     *
     * @return void
     */
    public function __construct(object|string $objectOrClass) {
        $reflector   = new ReflectionClass($objectOrClass);
        $constructor = $reflector->getConstructor();

        if (is_string($objectOrClass) && class_exists($objectOrClass)) {
            // Use Reflection to inspect the class
            $reflector   = new ReflectionClass($objectOrClass);
            $constructor = $reflector->getConstructor();

            // Check if the constructor is not public, and make it accessible
            if ($constructor && !$constructor->isPublic()) {
                $constructor->setAccessible(true);
                $this->object = $reflector->newInstanceWithoutConstructor();
                $constructor->invoke($this->object);
            } else {
                // Instantiate normally if the constructor is public or does not exist
                $this->object = new $objectOrClass;
            }
        } else {
            // Use the object as is if it's already an object
            $this->object = $objectOrClass;
        }

        $this->reflection = new ReflectionClass($this->object);
    }

    #endregion
    #region Properties

    /**
     * Magic __get method to get properties from the internal object.
     *
     * @param string $propertyName The name of the property being accessed.
     *
     * @return mixed The value of the property.
     * @throws ReflectionException
     */
    public function __get(string $propertyName) {
        $property = $this->getProperty($propertyName);
        $value    = $property->getValue($this->object);

        // Wrap returned objects in a new ReflectionWrapper instance
        if (is_object($value)) {
            return self::for($value);
        }

        // Or just return value for everything else
        return $value;
    }

    /**
     * Magic __set method to set properties on the internal object.
     *
     * @param string $propertyName The name of the property being set.
     * @param mixed  $value        The value to set.
     *
     * @throws ReflectionException
     */
    public function __set(string $propertyName, mixed $value): void {
        $property = $this->getProperty($propertyName);
        $property->setValue($this->object, $value);
    }

    /**
     * Gets a property reflection object, sets accessibility.
     *
     * @param string $propertyName
     * @return ReflectionProperty
     * @throws ReflectionException|InvalidArgumentException
     */
    private function getProperty(string $propertyName): ReflectionProperty {
        if (!$this->reflection->hasProperty($propertyName)) {
            throw new InvalidArgumentException("Property $propertyName does not exist in " . get_class($this->object) . ".");
        }

        $property = $this->reflection->getProperty($propertyName);
        if (!$property->isPublic()) {
            $property->setAccessible(true);
        }
        return $property;
    }

    #endregion
    #region Methods

    /**
     * Magic __call method to forward method calls to the internal object.
     *
     * @param string $methodName The name of the method being called.
     * @param array  $args       The arguments to pass to the method.
     *
     * @return mixed The result of the method call.
     */
    public function __call(string $methodName, array $args): mixed {
        return $this->callMethodOrMagic($methodName, $args, false);
    }

    /**
     * Instance method to forward static method calls to the internal object's class.
     *
     * @param string $methodName The name of the static method being called.
     * @param mixed ...$args The arguments to pass to the static method.
     *
     * @return mixed The result of the static method call.
     */
    public function callStatic(string $methodName, ...$args): mixed {
        return $this->callMethodOrMagic($methodName, $args, true);
    }

    private function callMethodOrMagic(string $methodName, $args, bool $callStatic): mixed {
        $reflection = $this->reflection;

        // if method exists, make it public and invoke it
        if ($reflection->hasMethod($methodName)) {
            $method = $reflection->getMethod($methodName);
            if (!$method->isPublic()) {
                $method->setAccessible(true);
            }
            return $method->invokeArgs($this->object, $args);
        }

        // else try magic methods
        $magicMethod = $callStatic ? '__callStatic' : '__call';
        if ($reflection->hasMethod($magicMethod)) {
            return $this->object->$magicMethod($methodName, $args);
        }

        // error checking
        throw new BadMethodCallException("Method $methodName does not exist.");
    }



}
#endregion
