// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/// Escrow SafePay. Sumber kebenaran status + dana. MySQL cuma cermin.
contract SafePayEscrow is ReentrancyGuard {
    using SafeERC20 for IERC20;

    enum Status { Created, Funded, Shipped, Released, Refunded, Disputed }

    struct Payment {
        address buyer;
        address seller;
        uint256 amount;
        Status status;
        uint64 deadline;
    }

    IERC20 public immutable token;
    address public immutable arbiter;
    uint256 public nextId;
    mapping(uint256 => Payment) public payments;

    event PaymentCreated(uint256 indexed id, address indexed buyer, address indexed seller, uint256 amount);
    event Shipped(uint256 indexed id);
    event Released(uint256 indexed id, uint256 amount);
    event Refunded(uint256 indexed id, uint256 amount);
    event Disputed(uint256 indexed id, string reason);
    event DisputeResolved(uint256 indexed id, bool toSeller);

    constructor(address token_, address arbiter_) {
        require(token_ != address(0), "invalid token");
        require(arbiter_ != address(0), "invalid arbiter");
        token = IERC20(token_);
        arbiter = arbiter_;
    }

    function createPayment(address seller, uint256 amount, uint64 deadline)
        external
        returns (uint256 id)
    {
        require(seller != address(0), "invalid seller");
        require(seller != msg.sender, "invalid recipient");
        require(amount > 0, "invalid amount");
        require(deadline > block.timestamp, "invalid deadline");

        id = nextId++;
        payments[id] = Payment({
            buyer: msg.sender,
            seller: seller,
            amount: amount,
            status: Status.Funded,
            deadline: deadline
        });

        token.safeTransferFrom(msg.sender, address(this), amount);
        emit PaymentCreated(id, msg.sender, seller, amount);
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
        token.safeTransfer(p.seller, p.amount);
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
        token.safeTransfer(p.buyer, p.amount);
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
        token.safeTransfer(toSeller ? p.seller : p.buyer, p.amount);
        emit DisputeResolved(id, toSeller);
    }
}
