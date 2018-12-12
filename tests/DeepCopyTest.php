<?php

namespace atk4\data\tests;

use atk4\data\Model;
use atk4\data\Util\DeepCopy;

class DCInvoice extends Model
{
    public $table = 'invoice';

    public function init()
    {
        parent::init();

        $this->hasMany('Lines', [new DCInvoiceLine(), 'their_field'=>'parent_id'])
            ->addField('total', ['aggregate'=>'sum', 'field'=>'total']);

        $this->addField('ref');

        $this->addField('is_paid', ['type'=>'boolean', 'default'=>false]);
    }
}

class DCQuote extends Model
{
    public $table = 'quote';

    public function init()
    {
        parent::init();

        $this->hasMany('Lines', [new DCQuoteLine(), 'their_field'=>'parent_id'])
            ->addField('total', ['aggregate'=>'sum', 'field'=>'total']);

        $this->addField('ref');

        $this->addField('is_converted', ['type'=>'boolean', 'default'=>false]);
    }
}

class DCInvoiceLine extends Model
{
    public $table = 'line';

    public function init()
    {
        parent::init();
        $this->hasOne('parent_id', new DCInvoice());

        $this->addField('name');

        $this->addField('type', ['enum'=>['invoice', 'quote']]);
        $this->addCondition('type', '=', 'invoice');

        $this->addField('qty', ['type'=>'integer']);
        $this->addField('price', ['type'=>'money']);
        $this->addField('vat', ['type'=>'numeric', 'default'=>0.21]);

        // total is calculated with VAT
        $this->addExpression('total', '[qty]*[price]*(1+vat)');
    }
}

class DCQuoteLine extends Model
{
    public $table = 'line';

    public function init()
    {
        parent::init();

        $this->hasOne('parent_id', new DCQuote());

        $this->addField('name');

        $this->addField('type', ['enum'=>['invoice', 'quote']]);
        $this->addCondition('type', '=', 'quote');

        $this->addField('qty', ['type'=>'integer']);
        $this->addField('price', ['type'=>'money']);

        // total is calculated WITHOUT VAT
        $this->addExpression('total', '[qty]*[price]');
    }
}

/**
 * Implements various tests for deep copying objects.
 */
class DeepCopyTest extends \atk4\schema\PHPUnit_SchemaTestCase
{
    public function setUp()
    {
        parent::setUp();

        // populate database for our three models
        $this->getMigration(new DCInvoice($this->db))->drop()->create();
        $this->getMigration(new DCQuote($this->db))->drop()->create();
        $this->getMigration(new DCInvoiceLine($this->db))->drop()->create();
    }

    public function testBasic()
    {
        $quote = new DCQuote($this->db);

        $quote->insert(['ref'=> 'q1', 'Lines'=> [
            ['tools', 'qty'=>5, 'price'=>10],
            ['work', 'qty'=>1, 'price'=>40],
        ]]);
        $quote->loadAny();

        // total price should match
        $this->assertEquals(90.00, $quote['total']);

        $dc = new DeepCopy();
        $invoice = $dc
            ->from($quote)
            ->to(new DCInvoice())
            ->with(['Lines'])
            ->copy();

        // price now will be with VAT
        $this->assertEquals(108.90, $invoice['total']);
        $this->assertEquals(1, $invoice->id);

        // try to copy same record one more time
        $invoice = $dc->copy();

        // it returns destination model with previously copied record loaded
        $this->assertEquals(1, $invoice->id); // same id as above (previous invoice)

        // and now create new quote and copy it
        $quote->insert(['ref'=> 'q2', 'Lines'=> [
            ['tools', 'qty'=>3, 'price'=>15],
            ['work', 'qty'=>2, 'price'=>35],
        ]]);
        $quote->load(2);

        // total price should match
        $this->assertEquals(115.00, $quote['total']);

        $invoice = $dc
            ->from($quote)
            ->to(new DCInvoice())
            ->with(['Lines'])
            ->copy();

        // price now will be with VAT and id will be 2 (new invoice)
        $this->assertEquals(139.15, $invoice['total']);
        $this->assertEquals(2, $invoice->id);
    }
}