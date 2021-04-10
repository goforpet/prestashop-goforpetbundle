<?php

/**
 * 2021 Go For Pet S.r.l.
 *
 * @author    Lucio Benini <dev@goforpet.com>
 * @copyright 2021 Go For Pet S.r.l.
 * @license   http://opensource.org/licenses/LGPL-3.0  The GNU Lesser General Public License, version 3.0 ( LGPL-3.0 )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;

class GoForPetBundle extends Module
{
    public function __construct()
    {
        $this->name          = 'goforpetbundle';
        $this->tab           = 'front_office_features';
        $this->version       = '1.0.0';
        $this->author        = 'Go For Pet';
        $this->need_instance = 1;

        parent::__construct();

        $this->displayName = $this->l('Go For Pet - Bundle');
        $this->description = $this->l('Cross-selling features for Go For Pet store.');

        $this->ps_versions_compliancy = array(
            'min' => '1.7',
            'max' => _PS_VERSION_
        );
    }

    public function install()
    {
        return parent::install() && $this->registerHook('displayFooterProduct');
    }

    public function hookDisplayFooterProduct($params)
    {
		$product  = $params['product'];
		$related = $this->getRelatedProduct($product);
		
		if ($related) {
			$factory = $this->getFactory();
			
			$this->smarty->assign('related', $factory->getPresenter()->present(
				$factory->getPresentationSettings(),
				$this->getProductPresenter($related),
				$this->context->language
			));
			
			return $this->display(__FILE__, 'views/templates/hook/displayFooterProduct.tpl');
		} else {
			return null;
		}
    }
	
	protected function getRelatedProduct($product) {
		$results = Db::getInstance()->executeS("
			SELECT
				cp.id_product
			FROM `" . _DB_PREFIX_ . "cart` cart
			INNER JOIN (
				SELECT
					cp.id_cart,
					COUNT(product.id_product) as count
				FROM `" . _DB_PREFIX_ . "cart_product` cp
				INNER JOIN `" . _DB_PREFIX_ . "product` product ON (product.id_product=cp.id_product)
				" . Shop::addSqlAssociation('product', 'product') . "
				WHERE product.id_product=" . pSQL($product->id) . "
				GROUP BY cp.id_cart
				ORDER BY count DESC
			) r ON (r.id_cart=cart.id_cart)
			INNER JOIN `" . _DB_PREFIX_ . "cart_product` cp ON (cp.id_product!=" . pSQL($product->id) . " AND cp.id_cart=cart.id_cart)
			ORDER BY count DESC
			LIMIT 1
        ");
		
		if (empty($results)) {
			$results = Db::getInstance()->executeS("
				SELECT product.id_product, SUM(od.product_quantity) as product_quantity
				FROM `" . _DB_PREFIX_ . "product` product
				" . Shop::addSqlAssociation('product', 'product') . "
				INNER JOIN `" . _DB_PREFIX_ . "category` category ON (category.id_category=product.id_category_default)
				INNER JOIN `" . _DB_PREFIX_ . "category` category_parent ON (category_parent.id_parent=category.id_parent)
				INNER JOIN `" . _DB_PREFIX_ . "category` category_parallel ON (category_parallel.id_parent=category_parent.id_category AND category_parallel.id_category!=product.id_category_default)
				LEFT JOIN `" . _DB_PREFIX_ . "order_detail` od ON (od.product_id = product.id_product AND od.product_id!=" . pSQL($product->id) . ")
				LEFT JOIN `" . _DB_PREFIX_ . "orders` o ON od.id_order = o.id_order
				GROUP BY od.product_id
				ORDER BY product_quantity DESC
				LIMIT 1
			");
			
			if (empty($results)) {
				$results = Db::getInstance()->executeS("
					SELECT product.id_product, SUM(od.product_quantity) as product_quantity
					FROM `" . _DB_PREFIX_ . "product` product
					" . Shop::addSqlAssociation('product', 'product') . "
					LEFT JOIN `" . _DB_PREFIX_ . "order_detail` od ON (od.product_id = product.id_product AND od.product_id!=" . pSQL($product->id) . ")
					LEFT JOIN `" . _DB_PREFIX_ . "orders` o ON od.id_order = o.id_order
					GROUP BY od.product_id
					ORDER BY product_quantity DESC
					LIMIT 1
				");
			}
		}
		
		return !empty($results) ? new Product($results[0]['id_product'], true, $this->context->language->id) : null;
	}
	
	protected function getProductPresenter($product) {
		$presenter = new ObjectPresenter();
		
		$o = $presenter->present($product);
		
        $o['id_product'] = (int) $product->id;
        $o['out_of_stock'] = (int) $product->out_of_stock;
        $o['new'] = (int) $product->new;
        $o['ecotax'] = Tools::convertPrice((float) $o['ecotax'], $this->context->currency, true, $this->context);

        $full = Product::getProductProperties($this->context->language->id, $o, $this->context);
		
        $full['show_quantities'] = (bool) (
            Configuration::get('PS_DISPLAY_QTIES')
            && Configuration::get('PS_STOCK_MANAGEMENT')
            && $product->quantity > 0
            && $product->available_for_order
            && !Configuration::isCatalogMode()
        );
        $full['quantity_label'] = ($product->quantity > 1) ? $this->trans('Items', [], 'Shop.Theme.Catalog') : $this->trans('Item', [], 'Shop.Theme.Catalog');

        if ($full['unit_price_ratio'] > 0) {
            $full['unit_price'] = (($this->getFactory()->getPresentationSettings()->include_taxes) ? $full['price'] : $full['price_tax_exc']) / $full['unit_price_ratio'];
        }

        $group_reduction = GroupReduction::getValueForProduct($product->id, (int) Group::getCurrent()->id);
		
        if ($group_reduction === false) {
            $group_reduction = Group::getReduction((int) $this->context->cookie->id_customer) / 100;
        }
        $full['customer_group_discount'] = $group_reduction;
        $full['title'] = $product->name;

        $full['rounded_display_price'] = Tools::ps_round(
            $full['price'],
            $this->context->currency->precision
        );
		
		return $full;
	}
	
    private function getFactory()
    {
        return new ProductPresenterFactory($this->context, new TaxConfiguration());
    }
}
