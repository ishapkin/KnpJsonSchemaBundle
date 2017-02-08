<?php

namespace Knp\JsonSchemaBundle\Schema;

use Knp\JsonSchemaBundle\Reflection\ReflectionFactory;
use Knp\JsonSchemaBundle\Schema\SchemaRegistry;
use Knp\JsonSchemaBundle\Model\SchemaFactory;
use Knp\JsonSchemaBundle\Model\Schema;
use Knp\JsonSchemaBundle\Model\PropertyFactory;
use Knp\JsonSchemaBundle\Model\Property;
use Knp\JsonSchemaBundle\Property\PropertyHandlerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SchemaGenerator
{
    protected $jsonValidator;
    protected $reflectionFactory;
    protected $schemaRegistry;
    protected $schemaFactory;
    protected $propertyFactory;
    protected $propertyHandlers;
    protected $aliases = array();
    protected $root = "";

    public function __construct(
        \JsonSchema\Validator $jsonValidator,
        UrlGeneratorInterface $urlGenerator,
        ReflectionFactory $reflectionFactory,
        SchemaRegistry $schemaRegistry,
        SchemaFactory $schemaFactory,
        PropertyFactory $propertyFactory
    )
    {
        $this->jsonValidator = $jsonValidator;
        $this->urlGenerator = $urlGenerator;
        $this->reflectionFactory = $reflectionFactory;
        $this->schemaRegistry = $schemaRegistry;
        $this->schemaFactory = $schemaFactory;
        $this->propertyFactory = $propertyFactory;
        $this->propertyHandlers = new \SplPriorityQueue;
        $this->objects = [];
        $this->cached = [];
        $this->lazy = [];
        $this->root = null;
    }

    /**
     * @deprecated
     *
     * @param $alias
     * @return Schema
     * @throws \Exception
     */
    public function generateLazy($alias)
    {

        $className = $this->schemaRegistry->getNamespace($alias);
        $refl = $this->reflectionFactory->create($className);
        $schema = $this->schemaFactory->createSchema(ucfirst($alias));

        $schema->setId($this->urlGenerator->generate('show_json_schema', array('alias' => $alias), true) . '#');
        $schema->setSchema(Schema::SCHEMA_V3);
        $schema->setType(Schema::TYPE_OBJECT);

        // each all fields
        foreach ($refl->getProperties() as $property) {
            $property = $this->propertyFactory->createProperty($property->name);
            $this->applyPropertyHandlers($className, $property);

            if (!$property->isIgnored() && $property->hasType(Property::TYPE_OBJECT) && $property->getObject()) {
                $property->setSchema($this->generate($property->getObject()));
            }

            $schema->addProperty($property);
        }

        return $schema;
    }


    public function generate($alias, $root = null, $depth = 0)
    {

        if (empty($this->root)) {
            $this->root = $alias;
        }
        $className = $this->schemaRegistry->getNamespace($alias);
        $refl = $this->reflectionFactory->create($className);
        $schema = $this->schemaFactory->createSchema(ucfirst($alias));

        $schema->setId($this->urlGenerator->generate('show_json_schema', array('alias' => $alias), true) . '#');
        $schema->setSchema(Schema::SCHEMA_V3);
        $schema->setType(Schema::TYPE_OBJECT);

        $this->objects[] = $alias;

        // each all fields
        foreach ($refl->getProperties() as $property) {
            $property = $this->propertyFactory->createProperty($property->name);
            $this->applyPropertyHandlers($className, $property);

            if (!$property->isIgnored() && $property->hasType(Property::TYPE_OBJECT) && $property->getObject()) {

                // property class name
                $propertyClass = $property->getObject();

                // если поле уже обрабатывалось то игнорим его
                if (!in_array($propertyClass, $this->objects)) {

                    // cached generated property schema
                    if (empty($this->cached[$propertyClass])) {
                        $this->cached[$propertyClass] = $this->generate($propertyClass, $propertyClass, $depth +1);
                    }

                    $property->setSchema($this->cached[$propertyClass]);

                } else {

                    // cached generated property schema
                    if (!empty($this->cached[$propertyClass])) {
                        $property->setSchema($this->cached[$propertyClass]);
                    } else {
                        $this->lazy[$propertyClass] = $depth;
                        $property->setLazy(true);
                    }
                }

            }

            if (!$property->isIgnored()) {
                $schema->addProperty($property);
            }

        }


        return $schema;
    }


    /**
     * @deprecated
     *
     * @param $propertyClass
     * @param $schema
     * @return mixed
     */
    public function recursive($propertyClass, $schema){
        foreach ($schema->getProperties() as $property) {

            $subSchema = $property->getSchema();

            if (!empty($subSchema)) {
                $this->recursive($propertyClass, $subSchema);
            }

            if ($property->getLazy() && $property->getObject() == $propertyClass) {

                $s = $this->generateLazy($propertyClass);
                $property->setSchema($s);

                $schema->addProperty($property);
            }
        }
        return $schema;
    }

    public function registerPropertyHandler(PropertyHandlerInterface $handler, $priority)
    {
        $this->propertyHandlers->insert($handler, $priority);
    }

    public function getPropertyHandlers()
    {
        return array_values(iterator_to_array(clone $this->propertyHandlers));
    }

    /**
     * Validate a schema against the meta-schema provided by http://json-schema.org/schema
     *
     * @param Schema $schema a json schema
     *
     * @return boolean
     */
    private function validateSchema(Schema $schema)
    {
        $this->jsonValidator->check(
            json_decode(json_encode($schema->jsonSerialize())),
            json_decode(file_get_contents($schema->getSchema()))
        );

        return $this->jsonValidator->isValid();
    }

    private function applyPropertyHandlers($className, Property $property)
    {
        $propertyHandlers = clone $this->propertyHandlers;

        while ($propertyHandlers->valid()) {
            $handler = $propertyHandlers->current();

            $handler->handle($className, $property);

            $propertyHandlers->next();
        }
    }
}