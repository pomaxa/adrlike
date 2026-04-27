<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert Product from enum column to products table with FK on decisions';
    }

    public function up(Schema $schema): void
    {
        // 1. Create products table
        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTS_NAME ON products (name)');

        // 2. Seed the four original product values
        $this->addSql(<<<'SQL'
            INSERT INTO products (id, name) VALUES
                (gen_random_uuid(), 'Leasing'),
                (gen_random_uuid(), 'Installment'),
                (gen_random_uuid(), 'Leaseback'),
                (gen_random_uuid(), 'All Product')
        SQL);

        // 3. Add nullable product_id column on decisions
        $this->addSql('ALTER TABLE decisions ADD product_id UUID DEFAULT NULL');

        // 4. Populate product_id from the existing string column
        $this->addSql(<<<'SQL'
            UPDATE decisions d
            SET product_id = p.id
            FROM products p
            WHERE p.name = d.product
        SQL);

        // 5. Enforce NOT NULL now that all rows are populated
        $this->addSql('ALTER TABLE decisions ALTER COLUMN product_id SET NOT NULL');

        // 6. Add FK + index
        $this->addSql('ALTER TABLE decisions ADD CONSTRAINT FK_decisions_product FOREIGN KEY (product_id) REFERENCES products (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_decisions_product_id ON decisions (product_id)');

        // 7. Drop the old enum string column
        $this->addSql('ALTER TABLE decisions DROP COLUMN product');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decisions DROP CONSTRAINT FK_decisions_product');
        $this->addSql('DROP INDEX IDX_decisions_product_id');

        // Re-create string column and repopulate from FK
        $this->addSql("ALTER TABLE decisions ADD product VARCHAR(32) NOT NULL DEFAULT 'All Product'");
        $this->addSql(<<<'SQL'
            UPDATE decisions d
            SET product = p.name
            FROM products p
            WHERE p.id = d.product_id
        SQL);
        $this->addSql('ALTER TABLE decisions DROP COLUMN product_id');
        $this->addSql('DROP TABLE products');
    }
}
