<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\block\ItemFrame;
use pocketmine\block\Sign;
use pocketmine\block\utils\SignText;
use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\BadPacketException;
use pocketmine\network\mcpe\NetworkNbtSerializer;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\BlockEntityDataPacket;
use pocketmine\network\mcpe\protocol\BlockPickRequestPacket;
use pocketmine\network\mcpe\protocol\BookEditPacket;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\CraftingEventPacket;
use pocketmine\network\mcpe\protocol\EntityEventPacket;
use pocketmine\network\mcpe\protocol\EntityFallPacket;
use pocketmine\network\mcpe\protocol\EntityPickRequestPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemFrameDropItemPacket;
use pocketmine\network\mcpe\protocol\LabTablePacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacketV1;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerHotbarPacket;
use pocketmine\network\mcpe\protocol\PlayerInputPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ServerSettingsRequestPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\ShowCreditsPacket;
use pocketmine\network\mcpe\protocol\SpawnExperienceOrbPacket;
use pocketmine\network\mcpe\protocol\SubClientLoginPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\types\MismatchTransactionData;
use pocketmine\network\mcpe\protocol\types\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\UseItemTransactionData;
use pocketmine\Player;
use function array_push;
use function base64_encode;
use function fmod;
use function implode;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function microtime;
use function preg_match;
use function preg_split;
use function trim;

/**
 * This handler handles packets related to general gameplay.
 */
class InGameSessionHandler extends SessionHandler{

	/** @var Player */
	private $player;
	/** @var NetworkSession */
	private $session;

	/** @var CraftingTransaction|null */
	protected $craftingTransaction = null;

	/** @var float */
	protected $lastRightClickTime = 0.0;
	/** @var Vector3|null */
	protected $lastRightClickPos = null;

	public function __construct(Player $player, NetworkSession $session){
		$this->player = $player;
		$this->session = $session;
	}

	public function handleText(TextPacket $packet) : bool{
		if($packet->type === TextPacket::TYPE_CHAT){
			return $this->player->chat($packet->message);
		}

		return false;
	}

	public function handleMovePlayer(MovePlayerPacket $packet) : bool{
		$yaw = fmod($packet->yaw, 360);
		$pitch = fmod($packet->pitch, 360);
		if($yaw < 0){
			$yaw += 360;
		}

		$this->player->setRotation($yaw, $pitch);
		$this->player->updateNextPosition($packet->position->subtract(0, 1.62, 0));

		return true;
	}

	public function handleLevelSoundEventPacketV1(LevelSoundEventPacketV1 $packet) : bool{
		return true; //useless leftover from 1.8
	}

	public function handleEntityEvent(EntityEventPacket $packet) : bool{
		$this->player->doCloseInventory();

		switch($packet->event){
			case EntityEventPacket::EATING_ITEM: //TODO: ignore this and handle it server-side
				if($packet->data === 0){
					return false;
				}

				$this->player->broadcastEntityEvent(EntityEventPacket::EATING_ITEM, $packet->data);
				break;
			default:
				return false;
		}

		return true;
	}

	public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{
		if($this->player->isSpectator()){
			$this->player->sendAllInventories();
			return true;
		}

		$result = true;

		if($packet->trData instanceof NormalTransactionData){
			$result = $this->handleNormalTransaction($packet->trData);
		}elseif($packet->trData instanceof MismatchTransactionData){
			$this->player->sendAllInventories();
			$result = true;
		}elseif($packet->trData instanceof UseItemTransactionData){
			$result = $this->handleUseItemTransaction($packet->trData);
		}elseif($packet->trData instanceof UseItemOnEntityTransactionData){
			$result = $this->handleUseItemOnEntityTransaction($packet->trData);
		}elseif($packet->trData instanceof ReleaseItemTransactionData){
			$result = $this->handleReleaseItemTransaction($packet->trData);
		}

		if(!$result){
			$this->player->sendAllInventories();
		}
		return $result;
	}

	private function handleNormalTransaction(NormalTransactionData $data) : bool{
		/** @var InventoryAction[] $actions */
		$actions = [];

		$isCrafting = false;
		$isFinalCraftingPart = false;
		foreach($data->getActions() as $networkInventoryAction){
			$isCrafting = $isCrafting || $networkInventoryAction->isCraftingPart();
			$isFinalCraftingPart = $isFinalCraftingPart || $networkInventoryAction->isFinalCraftingPart();

			try{
				$action = $networkInventoryAction->createInventoryAction($this->player);
				if($action !== null){
					$actions[] = $action;
				}
			}catch(\UnexpectedValueException $e){
				$this->session->getLogger()->debug("Unhandled inventory action: " . $e->getMessage());
				return false;
			}
		}

		if($isCrafting){
			//we get the actions for this in several packets, so we need to wait until we have all the pieces before
			//trying to execute it

			if($this->craftingTransaction === null){
				$this->craftingTransaction = new CraftingTransaction($this->player, $actions);
			}else{
				foreach($actions as $action){
					$this->craftingTransaction->addAction($action);
				}
			}

			if($isFinalCraftingPart){
				try{
					$this->craftingTransaction->execute();
				}catch(TransactionValidationException $e){
					$this->session->getLogger()->debug("Failed to execute crafting transaction: " . $e->getMessage());
					return false;
				}finally{
					$this->craftingTransaction = null;
				}
			}
		}else{
			//normal transaction fallthru
			if($this->craftingTransaction !== null){
				$this->session->getLogger()->debug("Got unexpected normal inventory action with incomplete crafting transaction, refusing to execute crafting");
				$this->craftingTransaction = null;
				return false;
			}

			$transaction = new InventoryTransaction($this->player, $actions);
			try{
				$transaction->execute();
			}catch(TransactionValidationException $e){
				$logger = $this->session->getLogger();
				$logger->debug("Failed to execute inventory transaction: " . $e->getMessage());
				$logger->debug("Actions: " . json_encode($data->getActions()));

				return false;
			}

			//TODO: fix achievement for getting iron from furnace
		}

		return true;
	}

	private function handleUseItemTransaction(UseItemTransactionData $data) : bool{
		switch($data->getActionType()){
			case UseItemTransactionData::ACTION_CLICK_BLOCK:
				//TODO: start hack for client spam bug
				$clickPos = $data->getClickPos();
				$spamBug = ($this->lastRightClickPos !== null and
					microtime(true) - $this->lastRightClickTime < 0.1 and //100ms
					$this->lastRightClickPos->distanceSquared($clickPos) < 0.00001 //signature spam bug has 0 distance, but allow some error
				);
				//get rid of continued spam if the player clicks and holds right-click
				$this->lastRightClickPos = clone $clickPos;
				$this->lastRightClickTime = microtime(true);
				if($spamBug){
					return true;
				}
				//TODO: end hack for client spam bug

				$blockPos = $data->getBlockPos();
				if(!$this->player->interactBlock($blockPos, $data->getFace(), $clickPos)){
					$this->onFailedBlockAction($blockPos, $data->getFace());
				}
				return true;
			case UseItemTransactionData::ACTION_BREAK_BLOCK:
				$blockPos = $data->getBlockPos();
				if(!$this->player->breakBlock($blockPos)){
					$this->onFailedBlockAction($blockPos, null);
				}
				return true;
			case UseItemTransactionData::ACTION_CLICK_AIR:
				if(!$this->player->useHeldItem()){
					$this->player->getInventory()->sendHeldItem($this->player);
				}
				return true;
		}

		return false;
	}

	/**
	 * Internal function used to execute rollbacks when an action fails on a block.
	 *
	 * @param Vector3  $blockPos
	 * @param int|null $face
	 */
	private function onFailedBlockAction(Vector3 $blockPos, ?int $face) : void{
		$this->player->getInventory()->sendHeldItem($this->player);
		if($blockPos->distanceSquared($this->player) < 10000){
			$blocks = $blockPos->sidesArray();
			if($face !== null){
				$sidePos = $blockPos->getSide($face);

				/** @var Vector3[] $blocks */
				array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
			}else{
				$blocks[] = $blockPos;
			}
			$this->player->getWorld()->sendBlocks([$this->player], $blocks);
		}
	}

	private function handleUseItemOnEntityTransaction(UseItemOnEntityTransactionData $data) : bool{
		$target = $this->player->getWorld()->getEntity($data->getEntityRuntimeId());
		if($target === null){
			return false;
		}

		//TODO: use transactiondata for rollbacks here
		switch($data->getActionType()){
			case UseItemOnEntityTransactionData::ACTION_INTERACT:
				if(!$this->player->interactEntity($target, $data->getClickPos())){
					$this->player->getInventory()->sendHeldItem($this->player);
				}
				return true;
			case UseItemOnEntityTransactionData::ACTION_ATTACK:
				if(!$this->player->attackEntity($target)){
					$this->player->getInventory()->sendHeldItem($this->player);
				}
				return true;
		}

		return false;
	}

	private function handleReleaseItemTransaction(ReleaseItemTransactionData $data) : bool{
		//TODO: use transactiondata for rollbacks here (resending entire inventory is very wasteful)
		switch($data->getActionType()){
			case ReleaseItemTransactionData::ACTION_RELEASE:
				if(!$this->player->releaseHeldItem()){
					$this->player->getInventory()->sendContents($this->player);
				}
				return true;
			case ReleaseItemTransactionData::ACTION_CONSUME:
				if(!$this->player->consumeHeldItem()){
					$this->player->getInventory()->sendHeldItem($this->player);
				}
				return true;
		}

		return false;
	}

	public function handleMobEquipment(MobEquipmentPacket $packet) : bool{
		if(!$this->player->equipItem($packet->hotbarSlot)){
			$this->player->getInventory()->sendHeldItem($this->player);
		}
		return true;
	}

	public function handleMobArmorEquipment(MobArmorEquipmentPacket $packet) : bool{
		return true; //Not used
	}

	public function handleInteract(InteractPacket $packet) : bool{
		if($packet->action === InteractPacket::ACTION_MOUSEOVER){
			//TODO HACK: silence useless spam (MCPE 1.8)
			//due to some messy Mojang hacks, it sends this when changing the held item now, which causes us to think
			//the inventory was closed when it wasn't.
			//this is also sent whenever entity metadata updates, which can get really spammy.
			//TODO: implement handling for this where it matters
			return true;
		}
		return false; //TODO
	}

	public function handleBlockPickRequest(BlockPickRequestPacket $packet) : bool{
		return $this->player->pickBlock(new Vector3($packet->blockX, $packet->blockY, $packet->blockZ), $packet->addUserData);
	}

	public function handleEntityPickRequest(EntityPickRequestPacket $packet) : bool{
		return false; //TODO
	}

	public function handlePlayerAction(PlayerActionPacket $packet) : bool{
		$pos = new Vector3($packet->x, $packet->y, $packet->z);

		switch($packet->action){
			case PlayerActionPacket::ACTION_START_BREAK:
				if(!$this->player->attackBlock($pos, $packet->face)){
					$this->onFailedBlockAction($pos, $packet->face);
				}

				break;

			case PlayerActionPacket::ACTION_ABORT_BREAK:
			case PlayerActionPacket::ACTION_STOP_BREAK:
				$this->player->stopBreakBlock($pos);
				break;
			case PlayerActionPacket::ACTION_START_SLEEPING:
				//unused
				break;
			case PlayerActionPacket::ACTION_STOP_SLEEPING:
				$this->player->stopSleep();
				break;
			case PlayerActionPacket::ACTION_JUMP:
				$this->player->jump();
				return true;
			case PlayerActionPacket::ACTION_START_SPRINT:
				if(!$this->player->toggleSprint(true)){
					$this->player->sendData($this->player);
				}
				return true;
			case PlayerActionPacket::ACTION_STOP_SPRINT:
				if(!$this->player->toggleSprint(false)){
					$this->player->sendData($this->player);
				}
				return true;
			case PlayerActionPacket::ACTION_START_SNEAK:
				if(!$this->player->toggleSneak(true)){
					$this->player->sendData($this->player);
				}
				return true;
			case PlayerActionPacket::ACTION_STOP_SNEAK:
				if(!$this->player->toggleSneak(false)){
					$this->player->sendData($this->player);
				}
				return true;
			case PlayerActionPacket::ACTION_START_GLIDE:
			case PlayerActionPacket::ACTION_STOP_GLIDE:
				break; //TODO
			case PlayerActionPacket::ACTION_CONTINUE_BREAK:
				$this->player->continueBreakBlock($pos, $packet->face);
				break;
			case PlayerActionPacket::ACTION_START_SWIMMING:
				break; //TODO
			case PlayerActionPacket::ACTION_STOP_SWIMMING:
				//TODO: handle this when it doesn't spam every damn tick (yet another spam bug!!)
				break;
			default:
				$this->session->getLogger()->debug("Unhandled/unknown player action type " . $packet->action);
				return false;
		}

		$this->player->setUsingItem(false);

		return true;
	}

	public function handleEntityFall(EntityFallPacket $packet) : bool{
		return true; //Not used
	}

	public function handleAnimate(AnimatePacket $packet) : bool{
		return $this->player->animate($packet->action);
	}

	public function handleContainerClose(ContainerClosePacket $packet) : bool{
		return $this->player->doCloseWindow($packet->windowId);
	}

	public function handlePlayerHotbar(PlayerHotbarPacket $packet) : bool{
		return true; //this packet is useless
	}

	public function handleCraftingEvent(CraftingEventPacket $packet) : bool{
		return true; //this is a broken useless packet, so we don't use it
	}

	public function handleAdventureSettings(AdventureSettingsPacket $packet) : bool{
		if($packet->entityUniqueId !== $this->player->getId()){
			return false; //TODO: operators can change other people's permissions using this
		}

		$handled = false;

		$isFlying = $packet->getFlag(AdventureSettingsPacket::FLYING);
		if($isFlying !== $this->player->isFlying()){
			if(!$this->player->toggleFlight($isFlying)){
				$this->session->syncAdventureSettings($this->player);
			}
			$handled = true;
		}

		//TODO: check for other changes

		return $handled;
	}

	public function handleBlockEntityData(BlockEntityDataPacket $packet) : bool{
		$pos = new Vector3($packet->x, $packet->y, $packet->z);
		if($pos->distanceSquared($this->player) > 10000){
			return false;
		}

		$block = $this->player->getWorld()->getBlock($pos);
		try{
			$offset = 0;
			$nbt = (new NetworkNbtSerializer())->read($packet->namedtag, $offset, 512)->getTag();
		}catch(NbtDataException $e){
			throw new BadPacketException($e->getMessage(), 0, $e);
		}

		if($block instanceof Sign){
			if($nbt->hasTag("Text", StringTag::class)){
				try{
					$text = SignText::fromBlob($nbt->getString("Text"));
				}catch(\InvalidArgumentException $e){
					throw new BadPacketException("Invalid sign text update: " . $e->getMessage(), 0, $e);
				}

				try{
					if(!$block->updateText($this->player, $text)){
						$this->player->getWorld()->sendBlocks([$this->player], [$block]);
					}
				}catch(\UnexpectedValueException $e){
					throw new BadPacketException($e->getMessage(), 0, $e);
				}

				return true;
			}

			$this->session->getLogger()->debug("Invalid sign update data: " . base64_encode($packet->namedtag));
		}

		return false;
	}

	public function handlePlayerInput(PlayerInputPacket $packet) : bool{
		return false; //TODO
	}

	public function handleSetPlayerGameType(SetPlayerGameTypePacket $packet) : bool{
		if($packet->gamemode !== $this->player->getGamemode()->getMagicNumber()){
			//Set this back to default. TODO: handle this properly
			$this->session->syncGameMode($this->player->getGamemode());
			$this->session->syncAdventureSettings($this->player);
		}
		return true;
	}

	public function handleSpawnExperienceOrb(SpawnExperienceOrbPacket $packet) : bool{
		return false; //TODO
	}

	public function handleMapInfoRequest(MapInfoRequestPacket $packet) : bool{
		return false; //TODO
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->radius);

		return true;
	}

	public function handleItemFrameDropItem(ItemFrameDropItemPacket $packet) : bool{
		$block = $this->player->getWorld()->getBlockAt($packet->x, $packet->y, $packet->z);
		if($block instanceof ItemFrame and $block->getFramedItem() !== null){
			return $this->player->attackBlock(new Vector3($packet->x, $packet->y, $packet->z), $block->getFacing());
		}
		return false;
	}

	public function handleBossEvent(BossEventPacket $packet) : bool{
		return false; //TODO
	}

	public function handleShowCredits(ShowCreditsPacket $packet) : bool{
		return false; //TODO: handle resume
	}

	public function handleCommandRequest(CommandRequestPacket $packet) : bool{
		return $this->player->chat($packet->command);
	}

	public function handleCommandBlockUpdate(CommandBlockUpdatePacket $packet) : bool{
		return false; //TODO
	}

	public function handlePlayerSkin(PlayerSkinPacket $packet) : bool{
		return $this->player->changeSkin($packet->skin, $packet->newSkinName, $packet->oldSkinName);
	}

	public function handleSubClientLogin(SubClientLoginPacket $packet) : bool{
		return false; //TODO
	}

	public function handleBookEdit(BookEditPacket $packet) : bool{
		return $this->player->handleBookEdit($packet);
	}

	public function handleModalFormResponse(ModalFormResponsePacket $packet) : bool{
		return $this->player->onFormSubmit($packet->formId, self::stupid_json_decode($packet->formData, true));
	}

	/**
	 * Hack to work around a stupid bug in Minecraft W10 which causes empty strings to be sent unquoted in form responses.
	 *
	 * @param string $json
	 * @param bool   $assoc
	 *
	 * @return mixed
	 * @throws BadPacketException
	 */
	private static function stupid_json_decode(string $json, bool $assoc = false){
		if(preg_match('/^\[(.+)\]$/s', $json, $matches) > 0){
			$parts = preg_split('/(?:"(?:\\"|[^"])*"|)\K(,)/', $matches[1]); //Splits on commas not inside quotes, ignoring escaped quotes
			foreach($parts as $k => $part){
				$part = trim($part);
				if($part === ""){
					$part = "\"\"";
				}
				$parts[$k] = $part;
			}

			$fixed = "[" . implode(",", $parts) . "]";
			if(($ret = json_decode($fixed, $assoc)) === null){
				throw new BadPacketException("Failed to fix JSON: " . json_last_error_msg() . "(original: $json, modified: $fixed)");
			}

			return $ret;
		}

		return json_decode($json, $assoc);
	}

	public function handleServerSettingsRequest(ServerSettingsRequestPacket $packet) : bool{
		return false; //TODO: GUI stuff
	}

	public function handleLabTable(LabTablePacket $packet) : bool{
		return false; //TODO
	}

	public function handleLevelSoundEvent(LevelSoundEventPacket $packet) : bool{
		$this->player->getWorld()->broadcastPacketToViewers($this->player->asVector3(), $packet);
		return true;
	}

	public function handleNetworkStackLatency(NetworkStackLatencyPacket $packet) : bool{
		return true; //TODO: implement this properly - this is here to silence debug spam from MCPE dev builds
	}
}
