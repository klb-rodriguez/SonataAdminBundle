<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Builder\ORM;

use Sonata\AdminBundle\Admin\ORM\FieldDescription;
use Sonata\AdminBundle\Form\ValueTransformer\EntityToIDTransformer;
use Sonata\AdminBundle\Form\ValueTransformer\ArrayToObjectTransformer;
use Sonata\AdminBundle\Form\EditableCollectionField;
use Sonata\AdminBundle\Form\EditableFieldGroup;
use Sonata\AdminBundle\Admin\FieldDescriptionInterface;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Builder\FormContractorInterface;

use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\ValidatorInterface;

use Doctrine\ORM\Mapping\ClassMetadataInfo;

class FormContractor implements FormContractorInterface
{

    protected $fieldFactory;

    protected $validator;

    /**
     * built-in definition
     *
     * @var array
     */
    protected $formTypes = array(
        'string'     =>  'text',
        'text'       =>  'textarea',
        'boolean'    =>  'checkbox',
        'checkbox'   =>  'checkbox',
        'integer'    =>  'integer',
        'tinyint'    =>  'integer',
        'smallint'   =>  'integer',
        'mediumint'  =>  'integer',
        'bigint'     =>  'integer',
        'decimal'    =>  'number',
        'datetime'   =>  'datetime',
        'date'       =>  'date',
        'choice'     =>  'choice',
        'array'      =>  'collection',
        'country'    =>  'country',
    );

    public function __construct(FormFactoryInterface $formFactory, ValidatorInterface $validator)
    {
        $this->formFactory = $formFactory;
        $this->validator    = $validator;
    }

    /**
     * Returns the field associated to a FieldDescription
     *   ie : build the embedded form from the related AdminInterface instance
     *
     * @throws RuntimeException
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @param null $fieldName
     * @return FieldGroup
     */
    protected function defineChildFormBuilder(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription, $fieldName = null)
    {
        $fieldName = $fieldName ?: $fieldDescription->getFieldName();

        $associatedAdmin = $fieldDescription->getAssociationAdmin();

        if (!$associatedAdmin) {
            throw new \RuntimeException(sprintf('inline mode for field `%s` required an Admin definition', $fieldName));
        }

        // retrieve the related object
        $targetObject = $associatedAdmin->getNewInstance();

        $childBuilder = $formBuilder->build($fieldName, 'form');
        $childBuilder->setData($targetObject);

        $associatedAdmin->defineFormBuilder($childBuilder);
    }


    /**
     * Returns the class associated to a FieldDescriptionInterface if any defined
     *
     * @throws RuntimeException
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return bool|string
     */
    public function getFormTypeName(FieldDescriptionInterface $fieldDescription)
    {
        $typeName = false;

        // the user redefined the mapping type, use the default built in definition
        if (!$fieldDescription->getFieldMapping() || $fieldDescription->getType() != $fieldDescription->getMappingType()) {
            $typeName = array_key_exists($fieldDescription->getType(), $this->formTypes) ? $this->formTypes[$fieldDescription->getType()] : false;
        } else if ($fieldDescription->getOption('form_field_type', false)) {
            $typeName = $fieldDescription->getOption('form_field_type', false);
        } else if (array_key_exists($fieldDescription->getType(), $this->formTypes)) {
            $typeName = $this->formTypes[$fieldDescription->getType()];
        }

        if (!$typeName) {
            throw new \RuntimeException(sprintf('No known form type for field `%s` (`%s`) is not implemented', $fieldDescription->getFieldName(), $fieldDescription->getType()));
        }

        return $typeName;
    }

    /**
     * Add a new instance to the related FieldDescriptionInterface value
     *
     * @param object $object
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return void
     */
    public function addNewInstance($object, FieldDescriptionInterface $fieldDescription)
    {
        $instance = $fieldDescription->getAssociationAdmin()->getNewInstance();
        $mapping  = $fieldDescription->getAssociationMapping();

        $method = sprintf('add%s', FieldDescription::camelize($mapping['fieldName']));

        $object->$method($instance);
    }

    /**
     * Returns an OneToOne associated field
     *
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return \Symfony\Component\Form\Type\FormTypeInterface
     */
    protected function getOneToOneField(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription)
    {
        // tweak the widget depend on the edit mode
        if ($fieldDescription->getOption('edit') == 'inline') {
            return $this->defineChildFormBuilder($formBuilder, $fieldDescription);
        }

        // TODO : remove this once an EntityField will be available
        $options = array(
            'value_transformer' => new EntityToIDTransformer(array(
                'em'        => $fieldDescription->getAdmin()->getModelManager()->getEntityManager(),
                'className' => $fieldDescription->getTargetEntity()
            ))
        );
        $options = array_merge($options, $fieldDescription->getOption('form_field_options', array()));

        if ($fieldDescription->getOption('edit') == 'list') {

            return new \Symfony\Component\Form\TextField($fieldDescription->getFieldName(), $options);
        }

        $class = $fieldDescription->getOption('form_field_type', false);

        // set valid default value
        if (!$class) {
            $instance = $this->getFieldFactory()->getInstance(
                $fieldDescription->getAdmin()->getClass(),
                $fieldDescription->getFieldName(),
                $fieldDescription->getOption('form_field_options', array())
            );
        } else {
            $instance = new $class($fieldDescription->getFieldName(), $options);
        }

        return $instance;
    }

    /**
     * Returns the OneToMany associated field
     *
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return \Symfony\Component\Form\Type\FormTypeInterface
     */
    protected function getOneToManyField(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription)
    {

        if ($fieldDescription->getOption('edit') == 'inline') {

            // build the prototype instance
            $this->defineChildFormBuilder($formBuilder, $fieldDescription);

            // retrieve the prototype
            $prototype = $formBuilder->get($fieldDescription->getFieldName());

            // delete the prototype instance from the builder
            $formBuilder->remove($fieldDescription->getFieldName());

            // create a collection type with the generated prototype
            $options = $fieldDescription->getOption('form_field_options', array());
            $options['prototype'] = $prototype;

            $formBuilder->add(
                $fieldDescription->getFieldName(),
                'sonata_admin_collection',
                $options
            );

            return;
//            $value = $fieldDescription->getValue($formBuilder->getData());
//
//            // add new instances if the min number is not matched
//            if ($fieldDescription->getOption('min', 0) > count($value)) {
//
//                $diff = $fieldDescription->getOption('min', 0) - count($value);
//                foreach (range(1, $diff) as $i) {
//                    $this->addNewInstance($formBuilder->getData(), $fieldDescription);
//                }
//            }

            // use custom one to expose the newfield method
//            return new \Sonata\AdminBundle\Form\EditableCollectionField($prototype);
        }

        return $this->getManyToManyField($formBuilder, $fieldDescription);
    }

    /**
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return \Symfony\Component\Form\Type\FormTypeInterface
     */
    protected function getManyToManyField(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription)
    {
        $typeName = $fieldDescription->getOption('form_field_type', 'doctrine_orm_one_to_many');
        $options  = $fieldDescription->getOption('form_field_options', array());

        $options['em']                  = $fieldDescription->getAdmin()->getModelManager()->getEntityManager();
        $options['class']               = $fieldDescription->getTargetEntity();
        $options['multiple']            = true;
        $options['field_description']   = $fieldDescription;

        $formBuilder->add($fieldDescription->getName(), $typeName, $options);
    }

    /**
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return \Symfony\Component\Form\Type\FormTypeInterface
     */
    protected function getManyToOneField(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription)
    {
        // tweak the widget depend on the edit mode
        if ($fieldDescription->getOption('edit') == 'inline') {
            return $this->defineChildFormBuilder($formBuilder, $fieldDescription);
        }

        $typeName = $fieldDescription->getOption('form_field_type', 'doctrine_orm_one_to_many');

        $options = array_merge_recursive(array(
            'em'        => $fieldDescription->getAdmin()->getModelManager()->getEntityManager(),
            'class'     => $fieldDescription->getTargetEntity(),
            'expanded'  => false,
            'edit'      => $fieldDescription->getOption('edit', 'standard')
        ), $fieldDescription->getOption('form_field_options', array()));

        $options['field_description']   = $fieldDescription;

        $formBuilder->add($fieldDescription->getName(), $typeName, $options);
    }

    /**
     * Add a new field type into the provided FormBuilder
     *
     * @param \Symfony\Component\Form\FormBuilder $form
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $name
     * @return void
     */
    public function addField(FormBuilder $formBuilder, FieldDescriptionInterface $fieldDescription)
    {
        switch ($fieldDescription->getType()) {
            case ClassMetadataInfo::ONE_TO_MANY:
                $this->getOneToManyField($formBuilder, $fieldDescription);
                break;

            case ClassMetadataInfo::MANY_TO_MANY:
                $this->getManyToManyField($formBuilder, $fieldDescription);
                break;

            case ClassMetadataInfo::MANY_TO_ONE:
                $this->getManyToOneField($formBuilder, $fieldDescription);
                break;

            case ClassMetadataInfo::ONE_TO_ONE:
                $this->getOneToOneField($formBuilder, $fieldDescription);
                break;

            default:
                $formBuilder->add(
                    $fieldDescription->getFieldName(),
                    $this->getFormTypeName($fieldDescription),
                    $fieldDescription->getOption('form_field_options', array())
                );
        }
    }

    /**
     * The method define the correct default settings for the provided FieldDescription
     *
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     * @return void
     */
    public function fixFieldDescription(AdminInterface $admin, FieldDescriptionInterface $fieldDescription, array $options = array())
    {

        $fieldDescription->mergeOptions($options);

        if($admin->getModelManager()->hasMetadata($admin->getClass()))
        {
            $metadata = $admin->getModelManager()->getMetadata($admin->getClass());

            // set the default field mapping
            if (isset($metadata->fieldMappings[$fieldDescription->getName()])) {
                $fieldDescription->setFieldMapping($metadata->fieldMappings[$fieldDescription->getName()]);
            }

            // set the default association mapping
            if (isset($metadata->associationMappings[$fieldDescription->getName()])) {
                $fieldDescription->setAssociationMapping($metadata->associationMappings[$fieldDescription->getName()]);
            }
        }

        if (!$fieldDescription->getType()) {
            throw new \RuntimeException(sprintf('Please define a type for field `%s` in `%s`', $fieldDescription->getName(), get_class($admin)));
        }

        $fieldDescription->setAdmin($admin);
        $fieldDescription->setOption('edit', $fieldDescription->getOption('edit', 'standard'));

        // fix template value for doctrine association fields
        if (!$fieldDescription->getTemplate()) {
             $fieldDescription->setTemplate(sprintf('SonataAdminBundle:CRUD:edit_%s.html.twig', $fieldDescription->getType()));
        }

        if ($fieldDescription->getType() == ClassMetadataInfo::ONE_TO_ONE) {
            $fieldDescription->setTemplate('SonataAdminBundle:CRUD:edit_orm_one_to_one.html.twig');
            $admin->attachAdminClass($fieldDescription);
        }

        if ($fieldDescription->getType() == ClassMetadataInfo::MANY_TO_ONE) {
            $fieldDescription->setTemplate('SonataAdminBundle:CRUD:edit_orm_many_to_one.html.twig');
            $admin->attachAdminClass($fieldDescription);
        }

        if ($fieldDescription->getType() == ClassMetadataInfo::MANY_TO_MANY) {
            $fieldDescription->setTemplate('SonataAdminBundle:CRUD:edit_orm_many_to_many.html.twig');
            $admin->attachAdminClass($fieldDescription);
        }

        if ($fieldDescription->getType() == ClassMetadataInfo::ONE_TO_MANY) {
            $fieldDescription->setTemplate('SonataAdminBundle:CRUD:edit_orm_one_to_many.html.twig');

            if ($fieldDescription->getOption('edit') == 'inline' && !$fieldDescription->getOption('widget_form_field')) {
                $fieldDescription->setOption('widget_form_field', 'Bundle\\Sonata\\AdminBundle\\Form\\EditableFieldGroup');
            }

            $admin->attachAdminClass($fieldDescription);
        }

        // set correct default value
        if ($fieldDescription->getType() == 'datetime') {
            $options = $fieldDescription->getOption('form_field_options', array());
            if (!isset($options['years'])) {
                $options['years'] = range(1900, 2100);
            }
            $fieldDescription->setOption('form_field', $options);
        }
    }

    public function getFormFactory()
    {
        return $this->formFactory;
    }

    /**
     * @param string $name
     * @param array $options
     * @return \Symfony\Component\Form\FormBuilder
     */
    public function getFormBuilder($name, array $options = array())
    {
        return $this->getFormFactory()->createBuilder('form', $name, $options);
    }

    public function getValidator()
    {
        return $this->validator;
    }
}