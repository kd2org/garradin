<?php

namespace Garradin\Entities\Accounting;

use Garradin\Entity;
use Garradin\DB;
use Garradin\Utils;
use Garradin\UserException;

class Account extends Entity
{
	const TABLE = 'acc_accounts';

	// Passif
	const LIABILITY = 1;

	// Actif
	const ASSET = 2;

	// Produit
	const REVENUE = 3;

	// Charge
	const EXPENSE = 4;

	const POSITIONS_NAMES = [
		'',
		'Passif',
		'Actif',
		'Produit',
		'Charge',
	];

	const TYPE_NONE = 0;
	const TYPE_BANK = 1;
	const TYPE_CASH = 2;

	/**
	 * Outstanding transaction accounts (like cheque or card payments)
	 */
	const TYPE_OUTSTANDING = 3;

	const TYPE_ANALYTICAL = 4;
	const TYPE_VOLUNTEERING = 5;

	const TYPE_BOOKMARK = 6;

	const TYPES_NAMES = [
		'',
		'Banque',
		'Caisse',
		'Attente d\'encaissement',
		'Analytique',
		'Bénévolat',
		'Favori',
	];

	protected $id;
	protected $id_plan;
	protected $code;
	protected $parent;
	protected $label;
	protected $description;
	protected $position;
	protected $type;
	protected $user;

	protected $_types = [
		'id'          => 'int',
		'id_plan'     => 'int',
		'code'        => 'string',
		'parent'      => '?int',
		'label'       => 'string',
		'description' => '?string',
		'position'    => 'int',
		'type'        => 'int',
		'user'        => 'int',
	];

	protected $_validation_rules = [
		'id_plan'     => 'required|integer|in_table:acc_plans,id',
		'code'        => 'required|string|alpha_num|max:8',
		'label'       => 'required|string|max:200',
		'description' => 'string|max:2000',
		'parent'      => 'required|nullable|integer|in_table:acc_accounts,id',
		'position'    => 'required|integer',
		'type'        => 'required|integer',
		'user'        => 'integer|min:0|max:1',
	];
}