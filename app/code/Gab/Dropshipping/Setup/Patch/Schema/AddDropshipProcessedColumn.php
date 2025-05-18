<?php
namespace Gab\Dropshipping\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class AddDropshipProcessedColumn implements SchemaPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $tableName = $this->moduleDataSetup->getTable('sales_order');

        if ($connection->isTableExists($tableName) && !$connection->tableColumnExists($tableName, 'dropship_processed')) {
            $connection->addColumn(
                $tableName,
                'dropship_processed',
                [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => true,
                    'default' => 0,
                    'comment' => 'Dropship Processed Flag'
                ]
            );
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
