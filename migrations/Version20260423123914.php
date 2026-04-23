<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423123914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE decision_history (id UUID NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, field_name VARCHAR(64) NOT NULL, old_value TEXT DEFAULT NULL, new_value TEXT DEFAULT NULL, decision_id UUID NOT NULL, changed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F378BB54BDEE7539 ON decision_history (decision_id)');
        $this->addSql('CREATE INDEX IDX_F378BB54828AD0A0 ON decision_history (changed_by_id)');
        $this->addSql('CREATE INDEX decision_history_lookup_idx ON decision_history (decision_id, changed_at)');
        $this->addSql('CREATE TABLE decisions (id UUID NOT NULL, decided_at DATE NOT NULL, product VARCHAR(32) NOT NULL, department VARCHAR(16) NOT NULL, clients_type VARCHAR(64) NOT NULL, change_description TEXT NOT NULL, comment TEXT DEFAULT NULL, as_is_metrics JSON DEFAULT NULL, to_be_metrics JSON DEFAULT NULL, follow_up_date DATE DEFAULT NULL, actual_result TEXT DEFAULT NULL, follow_up_status VARCHAR(16) NOT NULL, follow_up_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, import_hash VARCHAR(64) DEFAULT NULL, submitted_by_id UUID NOT NULL, approved_by_id UUID DEFAULT NULL, follow_up_owner_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_638DAA1779F7D87D ON decisions (submitted_by_id)');
        $this->addSql('CREATE INDEX IDX_638DAA172D234F6A ON decisions (approved_by_id)');
        $this->addSql('CREATE INDEX IDX_638DAA173E8BA26B ON decisions (follow_up_owner_id)');
        $this->addSql('CREATE INDEX decisions_decided_at_idx ON decisions (decided_at)');
        $this->addSql('CREATE INDEX decisions_follow_up_date_idx ON decisions (follow_up_date)');
        $this->addSql('CREATE INDEX decisions_follow_up_status_idx ON decisions (follow_up_status)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, placeholder BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        $this->addSql('ALTER TABLE decision_history ADD CONSTRAINT FK_F378BB54BDEE7539 FOREIGN KEY (decision_id) REFERENCES decisions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decision_history ADD CONSTRAINT FK_F378BB54828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decisions ADD CONSTRAINT FK_638DAA1779F7D87D FOREIGN KEY (submitted_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decisions ADD CONSTRAINT FK_638DAA172D234F6A FOREIGN KEY (approved_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decisions ADD CONSTRAINT FK_638DAA173E8BA26B FOREIGN KEY (follow_up_owner_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE decision_history DROP CONSTRAINT FK_F378BB54BDEE7539');
        $this->addSql('ALTER TABLE decision_history DROP CONSTRAINT FK_F378BB54828AD0A0');
        $this->addSql('ALTER TABLE decisions DROP CONSTRAINT FK_638DAA1779F7D87D');
        $this->addSql('ALTER TABLE decisions DROP CONSTRAINT FK_638DAA172D234F6A');
        $this->addSql('ALTER TABLE decisions DROP CONSTRAINT FK_638DAA173E8BA26B');
        $this->addSql('DROP TABLE decision_history');
        $this->addSql('DROP TABLE decisions');
        $this->addSql('DROP TABLE users');
    }
}
