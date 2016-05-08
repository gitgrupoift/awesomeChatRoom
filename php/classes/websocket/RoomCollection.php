<?php
/**
 * Room Collection
 *
 * @package    Collection
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\websocket;

use abstracts\Collection as Collection;
use classes\websocket\Room as Room;
use classes\managers\ChatManager as ChatManager;

/**
 * A collection of Room that extends the Collection pattern
 *
 * @method Room getObjectById($id) {
 *      Get a room by the room ID
 *
 *      @return Room The room
 * }
 *
 * @method Room getObjectByIndex($index) {
 *      Get a room by its index
 *
 *      @return Room The room
 * }
 */
class RoomCollection extends Collection
{
    /*=====================================
    =            Magic methods            =
    =====================================*/

    /**
     * constructor
     */
    public function __construct()
    {
    }

    /*-----  End of Magic methods  ------*/

    /*======================================
    =            Public methods            =
    ======================================*/

    /**
     * Add a room to the collection
     *
     * @param      Room  $room   The room
     * @param      null  $key    Not used parameter but need to be there because it is in the parent class
     *
     * @throws     Exception  If the Room is already in the collection
     */
    public function add($room, $key = null)
    {
        $id = $room->getId();

        if (array_key_exists($id, $this->indexId)) {
            throw new Exception(
                'This room' . $room . ' is already in the collection ' . $this,
                Exception::$WARNING
            );
        }

        $this->collection[] = $room;
        $this->indexId[$id] = $this->count() - 1;
    }

    /**
     * Remove a room from the collection
     *
     * @param      Room  $room   The room
     *
     * @throws     Exception  If the Room is not already in the collection
     */
    public function remove(Room $room)
    {
        $id = $room->getRoom()->id;

        if (!array_key_exists($id, $this->indexId)) {
            throw new Exception(
                'This room' . $room . ' is not already in the collection ' . $this,
                Exception::$WARNING
            );
        }

        $index = $this->indexId[$id];
        unset($this->indexId[$id]);
        unset($this->collection[$index]);
    }

    /**
     * Determine if room exist
     *
     * @param      int   $roomId  The room ID
     *
     * @return     bool  True if room exist, false otherwise.
     */
    public function isRoomExist(int $roomId): bool
    {
        return in_array($roomId, $this->indexId);
    }

    /**
     * Load all the rooms from the database in the collection
     */
    public function loadRooms()
    {
        $chatManager = new ChatManager();

        foreach ($chatManager->getAllRooms() as $chatRoom) {
            $this->add(new Room($chatRoom));
        }
    }

    /*=====  End of Public methods  ======*/
}
