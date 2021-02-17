<?php

namespace SilverShop\Stock\Extensions;

use League\CLImate\TerminalObject\Dynamic\Checkbox\Checkbox;
use SilverShop\Page\CartPageController;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DB;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use SilverStripe\CMS\Model\SiteTree;
use SilverShop\Stock\Model\ProductWarehouseStock;
use SilverShop\Stock\Model\ProductWarehouse;
use SilverShop\Stock\Forms\GridFieldProductStockField;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Order;
use SilverShop\Model\Variation\Variation;
use SilverShop\Model\OrderItem;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;

/**
 * An extension which can be applied to either the shop {@link Product} or
 * {@link Variation} class for including stock values in the CMS.
 *
 * Stock is held within a {@link ProductWarehouse}.
 */
class ProductStockExtension extends DataExtension
{
    private static $allow_out_of_stock_purchase = false;

    public function updateCMSFields(FieldList $fields)
    {
        if ($this->hasVariations()) {
            // it has variations so then we leave the management of the stock
            // level to the variation.
            $fields->addFieldToTab('Root.Stock', new LiteralField('StockManagedVariations',
                '<p>You have variations attached to this product. To manage the stock level '.
                'click the Stock tab on each of the variations</p>'
            ));

            return;
        }

        $grid = new GridField(
            'StockLevels',
            _t(__CLASS__ .'.Stock', 'Stock'),
            $this->getStockForEachWarehouse(),
            GridFieldConfig::create()
                ->addComponent(new GridFieldButtonRow('before'))
                ->addComponent(new GridFieldToolbarHeader())
                ->addComponent(new GridFieldTitleHeader())
                ->addComponent(new GridFieldEditableColumns())
                ->addComponent(new GridFieldProductStockField())
        );

        $config = $grid->getConfig();

        $gridFields = array(
            'Title' => array(
                'title' => _t(__CLASS__ . '.Title', 'Title'),
                'field' => ReadonlyField::class
            ),
            'Quantity'  => array(
                'title' => _t(__CLASS__ . '.Quantity', 'Quantity'),
                'callback' => function ($record, $column, $grid) {
                    // Numeric doesn't support null type
                    // return new NumericField($column);
                    return TextField::create($column);
                }
            )
        );

        if( Config::inst()->get(ProductWarehouseStock::class, 'use_unlimited_checkbox') ){
            $gridFields['Unlimited'] = array(
                'title' => _t(__CLASS__ . '.Unlimited', 'Unlimited'),
                'callback' => function ($record, $column, $grid) {
                    return CheckboxField::create($column, _t(__CLASS__ . '.Unlimited', 'Unlimited'));
                }
            );
        }

        $config->getComponentByType(GridFieldEditableColumns::class)->setDisplayFields($gridFields);

        // if the record has a root tab, (page) otherwise it could be a
        // dataobject so we'll just
        if ($fields->fieldByName('Root')) {
            $fields->addFieldToTab('Root.Stock', $grid);
        } else {
            $fields->push($grid);
        }
    }

    /**
     * Returns a list of all the warehouses with a value in use for the stock
     * GridField instance. Will create records for products that don't have
     * them.
     *
     * @return DataList
     */
    public function getStockForEachWarehouse()
    {
        $warehouses = ProductWarehouse::get();
        $output = new ArrayList();

        foreach ($warehouses as $warehouse) {
            if ($stock = $this->getStockForWarehouse($warehouse)) {
                $output->push($stock);
            }
        }

        return $output;
    }


    /**
     * Returns the ProductWarehouseStock for this product given a specific warehosue.
     * IT will create a ProductWarehouseStock record for the product in the warehouse if not found.
     *
     * @param ProductWarehouse $warehouse The warehouse
     * @param boolean $strictCreate Only create a ProductWarehouseStock record
     * if this (Buyable) record exists in the DB
     *
     * @return ProductWarehouseStock|null
     */
    public function getStockForWarehouse(ProductWarehouse $warehouse, $strictCreate = true)
    {
       $record = $warehouse->StockedProducts()->filter(array(
           'ProductID'=> $this->owner->ID,
           'ProductClass'=>$this->owner->ClassName
        ))->first();

        if (!$record && ($this->owner->isInDB() || !$strictCreate)) {

            $defaults = ProductWarehouseStock::config()->get('defaults');
            $record = Injector::inst()->create(ProductWarehouseStock::class);
            $record->WarehouseID = $warehouse->ID;
            $record->ProductID = $this->owner->ID;
            $record->ProductClass = $this->owner->ClassName;
            $record->Quantity = 0;

            foreach($defaults as $field => $val){
                $record->{$field} = $val;
            }

            $record->write();
        }

        return $record;
    }


    /**
     * @param int
     *
     * @return boolean
     */

    private static $_cached_hasAvailableStock = [];

    public function hasAvailableStock($require = 1)
    {

        if( isset( self::$_cached_hasAvailableStock[$this->owner->ClassName][$this->owner->ID] ) ){
            return self::$_cached_hasAvailableStock[$this->owner->ClassName][$this->owner->ID];
        }

        if ($this->hasVariations()) {
            foreach ($this->owner->Variations() as $variation) {
                if ($variation->hasAvailableStock($require)) {
                    self::$_cached_hasAvailableStock[$this->owner->ClassName][$this->owner->ID] = 1;
                    return true;
                }
            }
        }

        if ($this->hasWarehouseWithUnlimitedStock()) {
            self::$_cached_hasAvailableStock[$this->owner->ClassName][$this->owner->ID] = 1;
            return true;
        } else {
            $stock = $this->getWarehouseStockQuantity();

            $pending = $this->getTotalStockInCarts();

            $result = ($stock - $pending) >= $require;
            self::$_cached_hasAvailableStock[$this->owner->ClassName][$this->owner->ID] = (int) $result;
            return $result;
        }
    }

    /**
     * Returns the number of items that are currently in other people's carts
     * which should be considered 'held'.
     *
     * @return int
     */
    public function getTotalStockInCarts()
    {

        $controller = Controller::curr();

        $cartID = 0;
        if( $controller instanceof CartPageController ){
            // only if in CART overview, exclude current cartid
            $current = ShoppingCart::curr();
            if( $current ){
                $cartID = $current->ID;
            }
        }

        if($this->owner->isVariation()){
            $identifier = "Variation";
            $identifier2 = "ProductVariation";
        } else {
            $identifier = "Product";
            $identifier2 = "Product";
        }

        $query = 'SELECT SUM(SilverShop_OrderItem.Quantity) AS QuantitySum
                        FROM SilverShop_OrderItem
                        LEFT JOIN SilverShop_'.$identifier.'_OrderItem ON SilverShop_'.$identifier.'_OrderItem.ID = SilverShop_OrderItem.ID
                        LEFT JOIN SilverShop_OrderAttribute ON SilverShop_OrderAttribute.ID=SilverShop_OrderItem.ID
                        LEFT JOIN SilverShop_Order ON SilverShop_Order.ID=SilverShop_OrderAttribute.OrderID
                        WHERE SilverShop_'.$identifier.'_OrderItem.'.$identifier2.'ID = ' . $this->owner->ID . '
                        AND SilverShop_Order.ID != ' . $cartID . '
                        AND SilverShop_Order.Status=\'Cart\'
                        GROUP BY SilverShop_'.$identifier.'_OrderItem.'.$identifier2.'ID';

        $result = DB::query($query)->next();

        if( $result ){
            return $result['QuantitySum'];
        }

        return 0;

    }

    /**
     * Returns whether a warehouse has unlimited stock for this product
     *
     * @return boolean
     */
    public function hasWarehouseWithUnlimitedStock()
    {
        // use checkbox instead of -1
        if( Config::inst()->get(ProductWarehouseStock::class, 'use_unlimited_checkbox') ){
            return ($this->getWarehouseStock()->filter("Unlimited",1)->count() > 0);
        }

        return ($this->getWarehouseStock()->where("\"Quantity\" = -1")->count() > 0);
    }


    /**
     * @return DataList
     */
    public function getWarehouseStock()
    {
        return ProductWarehouseStock::get()->filter([
            'ProductID' => $this->owner->ID,
            'ProductClass' => $this->owner->getClassName()
        ]);
    }

    /**
     * Returns the number of available stock. Note this cannot be used to
     * determine if stock is available as a warehouse may have an unlimited
     * (null) value for stock.
     *
     * @return boolean
     */
    public function getWarehouseStockQuantity()
    {
        return $this->getWarehouseStock()->sum('Quantity');
    }

    /**
     * @return boolean
     */
    public function canPurchase($member = null, $quantity = 1)
    {

        if($this->getWarehouseStock()->count() < 1) {
            // no warehouses available.
            return true;
        }
        if ($this->hasVariations()) {
            // then just return. canPurchase will be called on those individual
            // variations, not the main product.
            return true;
        } else {
            $outOfStockAllowed = Config::inst()->get('allow_out_of_stock_purchase');

            if ($outOfStockAllowed) {
                return true;
            }

            // validate to the amount they want to purchase.
            if (!$this->hasAvailableStock($quantity)) {
                return false;
            }

            return true;
        }
    }

    /**
     * As stock can either be managed on a product or a product variation level,
     * return whether this object has variations enabled.
     *
     * @return boolean
     */
    public function hasVariations()
    {
        $schema = $this->owner->getSchema();
        $componentClass = $schema->hasManyComponent($this->owner->ClassName, 'Variations');

        return ($componentClass && $this->owner->Variations()->exists());
    }

    /**
     *
     * @return bool
     */
    public function isVariation()
    {
        return ($this->owner instanceof Variation);
    }

    /**
     * @return string
     */
    public function getStockBaseIdentifier()
    {
        return $this->owner->getClassName();
    }


    /**
     * Decrements the stock for a given order item. Potentially will reduce the
     * stock across multiple warehouses. If any of the warehouses have unlimited
     * stock, they're used a fallback.
     *
     * @param OrderItem $orderItem.
     */
    public function decrementStock(OrderItem $orderItem)
    {
        $quantity = $orderItem->Quantity;

        foreach ($this->getWarehouseStock() as $warehouse) {

            if ($warehouse->Quantity == "-1" || (Config::inst()->get(ProductWarehouseStock::class, 'use_unlimited_checkbox') && $warehouse->Unlimited )) {
                // unlimited
                break;
            }

            if ($quantity <= $warehouse->Quantity) {
                $warehouse->Quantity -= $quantity;
            } else {
                $quantity = $quantity - $warehouse->Quantity;
                $warehouse->Quantity = 0;
            }

            $warehouse->write();
        }

        return $this->owner;
    }
}
