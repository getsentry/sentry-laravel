<?php
/**
 * Created by PhpStorm.
 * User: haza
 * Date: 26.11.18
 * Time: 15:03
 */

namespace Sentry\Laravel;


use Symfony\Component\OptionsResolver\OptionsResolver;

class Options extends \Sentry\Options
{
    /**
     * @var bool Flag if breadcrumbs for sql queries should be added
     */
    private $sqlBreadcrumbs;

    /**
     * @return mixed
     */
    public function getSqlBreadcrumbs()
    {
        return $this->options['sql_breadcrumbs'];
    }

    /**
     * @param mixed $sqlBreadcrumbs
     */
    public function setSqlBreadcrumbs(bool $sqlBreadcrumbs): void
    {
        $options = array_merge($this->options, ['sql_breadcrumbs' => $sqlBreadcrumbs]);

        $this->options = $this->resolver->resolve($options);
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'sql_breadcrumbs' => true,
        ]);
        parent::configureOptions($resolver);
    }
}