// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/// Escrow SafePay berbasis ETH lokal Hardhat (1 ETH simulasi = Rp 1.000.000).
/// Sumber kebenaran status + dana ada di kontrak ini; MySQL hanya cermin tampilan.
contract SafePayEscrow is ReentrancyGuard {
    enum Status { Created, Funded, Shipped, Released, Refunded, Disputed }

    struct Payment {
        address payable buyer;
        address payable seller;
        uint256 amount; // disimpan dalam satuan wei
        Status status;
        uint64 deadline;
    }

    address public immutable arbiter;
    uint256 public nextId;
    mapping(uint256 => Payment) public payments;

    event PaymentCreated(uint256 indexed id, address indexed buyer, address indexed seller, uint256 amount);
    event Shipped(uint256 indexed id);
    event Released(uint256 indexed id, uint256 amount);
    event Refunded(uint256 indexed id, uint256 amount);
    event Disputed(uint256 indexed id, string reason);
    event DisputeResolved(uint256 indexed id, bool toSeller);

    constructor(address arbiter_) {
        require(arbiter_ != address(0), "invalid arbiter");
        arbiter = arbiter_;
    }

    function createPayment(address payable seller, uint64 deadline)
        external
        payable
        returns (uint256 id)
    {
        require(seller != address(0), "invalid seller");
        require(seller != msg.sender, "invalid recipient");
        require(msg.value > 0, "invalid amount");
        require(deadline > block.timestamp, "invalid deadline");

        id = nextId++;
        payments[id] = Payment({
            buyer: payable(msg.sender),
            seller: seller,
            amount: msg.value,
            status: Status.Funded,
            deadline: deadline
        });

        emit PaymentCreated(id, msg.sender, seller, msg.value);
    }

    function markShipped(uint256 id) external {
        Payment storage p = payments[id];
        require(msg.sender == p.seller, "not seller");
        require(p.status == Status.Funded, "bad status");
        p.status = Status.Shipped;
        emit Shipped(id);
    }

    // Status diubah sebelum transfer + nonReentrant: anti reentrancy.
    function confirmReceived(uint256 id) external nonReentrant {
        Payment storage p = payments[id];
        require(msg.sender == p.buyer, "not buyer");
        require(p.status == Status.Shipped, "not shipped");
        p.status = Status.Released;
        (bool ok, ) = p.seller.call{value: p.amount}("");
        require(ok, "transfer failed");
        emit Released(id, p.amount);
    }

    function refund(uint256 id) external nonReentrant {
        Payment storage p = payments[id];
        require(msg.sender == p.buyer, "not buyer");
        require(
            p.status == Status.Funded
                || (p.status == Status.Shipped && block.timestamp > p.deadline),
            "not refundable"
        );
        p.status = Status.Refunded;
        (bool ok, ) = p.buyer.call{value: p.amount}("");
        require(ok, "transfer failed");
        emit Refunded(id, p.amount);
    }

    function raiseDispute(uint256 id, string calldata reason) external {
        Payment storage p = payments[id];
        require(msg.sender == p.buyer || msg.sender == p.seller, "not party");
        require(p.status == Status.Funded || p.status == Status.Shipped, "bad status");
        p.status = Status.Disputed;
        emit Disputed(id, reason);
    }

    function resolveDispute(uint256 id, bool toSeller) external nonReentrant {
        Payment storage p = payments[id];
        require(msg.sender == arbiter, "not arbiter");
        require(p.status == Status.Disputed, "not disputed");
        p.status = toSeller ? Status.Released : Status.Refunded;
        address payable recipient = toSeller ? p.seller : p.buyer;
        (bool ok, ) = recipient.call{value: p.amount}("");
        require(ok, "transfer failed");
        emit DisputeResolved(id, toSeller);
    }
}
